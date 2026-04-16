<?php

namespace Dashed\DashedOmnisocials\Adapters;

use Dashed\DashedMarketing\Contracts\PublishingAdapter;
use Dashed\DashedMarketing\DTOs\PostStatus;
use Dashed\DashedMarketing\DTOs\PublishResult;
use Dashed\DashedMarketing\Models\SocialChannel;
use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Dashed\DashedOmnisocials\Exceptions\OmnisocialsApiException;
use Dashed\DashedOmnisocials\Support\ChannelPlatformMapper;
use Illuminate\Support\Facades\Log;

class OmnisocialsPublishAdapter implements PublishingAdapter
{
    public function publish(SocialPost $post): PublishResult
    {
        $client = new OmnisocialsClient($post->site_id);

        if (! $client->isConfigured()) {
            return new PublishResult(
                success: false,
                error: 'Omnisocials API key is niet geconfigureerd.',
            );
        }

        $channelSlugs = $post->channels ?? [];

        if (empty($channelSlugs)) {
            return new PublishResult(
                success: false,
                error: 'Geen kanalen geselecteerd voor deze post.',
            );
        }

        // Load channels and validate
        $channels = SocialChannel::withoutGlobalScopes()
            ->where('site_id', $post->site_id)
            ->whereIn('slug', $channelSlugs)
            ->get();

        $unsupported = [];
        $missingAccount = [];
        $accounts = [];

        foreach ($channels as $channel) {
            if (ChannelPlatformMapper::isUnsupported($channel->slug)) {
                $unsupported[] = $channel->slug;
                continue;
            }

            if (! ChannelPlatformMapper::isSupported($channel->slug)) {
                $unsupported[] = $channel->slug;
                continue;
            }

            if (! $channel->omnisocials_account_id) {
                $missingAccount[] = $channel->slug;
                continue;
            }

            $platform = ChannelPlatformMapper::toOmnisocials($channel->slug);
            $accounts[] = [
                'channel' => $channel,
                'platform' => $platform,
                'account_id' => $channel->omnisocials_account_id,
            ];
        }

        if (! empty($unsupported)) {
            return new PublishResult(
                success: false,
                error: 'Niet ondersteunde kanalen: ' . implode(', ', $unsupported),
            );
        }

        if (! empty($missingAccount)) {
            return new PublishResult(
                success: false,
                error: 'Omnisocials account ontbreekt voor kanalen: ' . implode(', ', $missingAccount),
            );
        }

        if (empty($accounts)) {
            return new PublishResult(
                success: false,
                error: 'Geen geldige kanalen gevonden.',
            );
        }

        try {
            // Upload media
            $mediaIds = $this->uploadMedia($client, $post, $accounts);

            // Build content object with per-platform overrides
            $content = $this->buildContent($post, $accounts);

            // Build media object with per-platform overrides
            $media = $this->buildMedia($mediaIds, $post, $accounts);

            // Build social accounts array
            $socialAccounts = array_map(fn (array $a) => [
                'id' => $a['account_id'],
                'platform' => $a['platform'],
            ], $accounts);

            $payload = [
                'social_accounts' => $socialAccounts,
                'content' => $content,
                'media' => $media,
            ];

            // Immediate publish or scheduled
            if ($post->scheduled_at && $post->scheduled_at->isFuture()) {
                $payload['scheduled_at'] = $post->scheduled_at->toIso8601String();
                $result = $client->createPost($payload);
            } else {
                $result = $client->publishPost($payload);
            }

            $externalId = $result['id'] ?? $result['post_id'] ?? null;

            $post->update([
                'external_id' => $externalId,
                'external_data' => $result,
            ]);

            return new PublishResult(
                success: true,
                externalId: $externalId,
                externalUrl: $result['url'] ?? null,
            );
        } catch (OmnisocialsApiException $e) {
            Log::error('Omnisocials publish failed', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ]);

            return new PublishResult(
                success: false,
                error: $e->getMessage(),
            );
        } catch (\Throwable $e) {
            Log::error('Omnisocials publish unexpected error', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            return new PublishResult(
                success: false,
                error: 'Onverwachte fout: ' . $e->getMessage(),
            );
        }
    }

    public function getStatus(SocialPost $post): PostStatus
    {
        if (! $post->external_id) {
            return PostStatus::PENDING_MANUAL;
        }

        $externalData = $post->external_data ?? [];
        $status = $externalData['status'] ?? null;

        return match ($status) {
            'published', 'completed' => PostStatus::PUBLISHED,
            'failed' => PostStatus::FAILED,
            'pending', 'scheduled', 'processing' => PostStatus::PENDING_MANUAL,
            default => PostStatus::UNKNOWN,
        };
    }

    public function supports(string $platform): bool
    {
        return ChannelPlatformMapper::isSupported($platform);
    }

    private function uploadMedia(OmnisocialsClient $client, SocialPost $post, array $accounts): array
    {
        $ratioImages = $post->ratio_images ?? [];
        $defaultImages = $post->images ?? [];
        $mediaIds = [];

        // Collect unique image URLs needed per ratio
        $urlsToUpload = [];

        foreach ($accounts as $account) {
            $ratio = ChannelPlatformMapper::defaultRatio($account['channel']->slug);
            $imageUrl = $ratioImages[$ratio] ?? $ratioImages['1:1'] ?? ($defaultImages[0] ?? null);

            if ($imageUrl && ! isset($urlsToUpload[$imageUrl])) {
                $urlsToUpload[$imageUrl] = null;
            }
        }

        // Upload each unique URL
        foreach ($urlsToUpload as $url => $unused) {
            $result = $client->uploadMediaFromUrl($url);
            $urlsToUpload[$url] = $result['id'] ?? $result['media_id'] ?? null;
        }

        // Map accounts to their media IDs
        foreach ($accounts as $account) {
            $ratio = ChannelPlatformMapper::defaultRatio($account['channel']->slug);
            $imageUrl = $ratioImages[$ratio] ?? $ratioImages['1:1'] ?? ($defaultImages[0] ?? null);
            $mediaIds[$account['channel']->slug] = $imageUrl ? $urlsToUpload[$imageUrl] : null;
        }

        return $mediaIds;
    }

    private function buildContent(SocialPost $post, array $accounts): array
    {
        $channelCaptions = $post->channel_captions ?? [];
        $defaultCaption = $post->caption ?? '';

        $content = ['default' => $defaultCaption];

        foreach ($accounts as $account) {
            $slug = $account['channel']->slug;
            $platform = $account['platform'];

            if (isset($channelCaptions[$slug]) && $channelCaptions[$slug] !== $defaultCaption) {
                $content[$platform] = $channelCaptions[$slug];
            }
        }

        return $content;
    }

    private function buildMedia(array $mediaIds, SocialPost $post, array $accounts): array
    {
        $defaultMediaId = null;
        $media = [];

        // Find the first available media ID as default
        foreach ($mediaIds as $id) {
            if ($id) {
                $defaultMediaId = $id;
                break;
            }
        }

        if (! $defaultMediaId) {
            return [];
        }

        $media['default'] = [$defaultMediaId];

        // Add per-platform overrides where media differs from default
        foreach ($accounts as $account) {
            $slug = $account['channel']->slug;
            $platform = $account['platform'];
            $id = $mediaIds[$slug] ?? null;

            if ($id && $id !== $defaultMediaId) {
                $media[$platform] = [$id];
            }
        }

        return $media;
    }
}
