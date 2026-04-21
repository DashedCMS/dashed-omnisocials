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
use Illuminate\Support\Facades\Storage;

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
                error: 'Niet ondersteunde kanalen: '.implode(', ', $unsupported),
            );
        }

        if (! empty($missingAccount)) {
            return new PublishResult(
                success: false,
                error: 'Omnisocials account ontbreekt voor kanalen: '.implode(', ', $missingAccount),
            );
        }

        if (empty($accounts)) {
            return new PublishResult(
                success: false,
                error: 'Geen geldige kanalen gevonden.',
            );
        }

        try {
            $content = $this->buildContent($post, $accounts);
            $mediaUrls = $this->buildMediaUrls($post, $accounts);
            $accountIds = array_map(fn (array $a) => $a['account_id'], $accounts);

            $payload = [
                'accounts' => $accountIds,
                'content' => $content,
            ];

            if (! empty($mediaUrls)) {
                $payload['media_urls'] = $mediaUrls;
            }

            // Immediate publish or scheduled
            if ($post->scheduled_at && $post->scheduled_at->isFuture()) {
                $payload['scheduled_at'] = $post->scheduled_at->toIso8601String();
                $result = $client->createPost($payload);
            } else {
                $result = $client->publishPost($payload);
            }

            $data = $result['data'] ?? $result;
            $externalId = $data['id'] ?? $data['post_id'] ?? null;
            $publishedUrls = $this->normalizePublishedUrls($data['published_urls'] ?? []);
            $externalUrl = $data['url'] ?? (reset($publishedUrls) ?: null);

            $update = [
                'external_id' => $externalId,
                'external_data' => $result,
            ];

            if (! empty($publishedUrls)) {
                $update['published_urls'] = $publishedUrls;
            }

            $post->update($update);

            return new PublishResult(
                success: true,
                externalId: $externalId,
                externalUrl: $externalUrl,
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
                error: 'Onverwachte fout: '.$e->getMessage(),
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

    private function buildMediaUrls(SocialPost $post, array $accounts): array
    {
        $ratioImages = $post->ratio_images ?? [];
        $defaultImages = $post->images ?? [];
        $defaultUrl = $this->toAbsoluteUrl($defaultImages[0] ?? ($ratioImages['1:1'] ?? null));

        if (! $defaultUrl && empty($ratioImages)) {
            return [];
        }

        $media = [];

        if ($defaultUrl) {
            $media['default'] = [$defaultUrl];
        }

        foreach ($accounts as $account) {
            $slug = $account['channel']->slug;
            $platform = $account['platform'];
            $ratio = ChannelPlatformMapper::defaultRatio($slug);
            $platformUrl = $this->toAbsoluteUrl($ratioImages[$ratio] ?? null);

            if ($platformUrl && $platformUrl !== $defaultUrl) {
                $media[$platform] = [$platformUrl];
            }
        }

        return $media;
    }

    private function toAbsoluteUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function buildContent(SocialPost $post, array $accounts): array
    {
        $channelCaptions = $post->channel_captions ?? [];
        $defaultCaption = $post->caption ?? '';
        $hashtags = $post->hashtags ?? [];
        $usePerChannel = (bool) ($post->captions_per_channel ?? false);

        $content = ['default' => $this->appendHashtags($defaultCaption, $hashtags)];

        if (! $usePerChannel) {
            return $content;
        }

        foreach ($accounts as $account) {
            $slug = $account['channel']->slug;
            $platform = $account['platform'];

            if (isset($channelCaptions[$slug]) && $channelCaptions[$slug] !== $defaultCaption) {
                $content[$platform] = $this->appendHashtags($channelCaptions[$slug], $hashtags);
            }
        }

        return $content;
    }

    private function appendHashtags(string $caption, array $hashtags): string
    {
        $tags = array_filter(array_map(function ($tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                return null;
            }

            return str_starts_with($tag, '#') ? $tag : '#'.ltrim($tag, '#');
        }, $hashtags));

        if (empty($tags)) {
            return $caption;
        }

        $tagLine = implode(' ', $tags);

        return $caption === '' ? $tagLine : rtrim($caption)."\n\n".$tagLine;
    }

    private function normalizePublishedUrls(mixed $raw): array
    {
        if (! is_array($raw) || empty($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $key => $value) {
            $url = match (true) {
                is_string($value) => $value,
                is_array($value) && isset($value['url']) => (string) $value['url'],
                is_array($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['url']) => (string) $value[0]['url'],
                default => null,
            };

            if ($url !== null && $url !== '') {
                $normalized[(string) $key] = $url;
            }
        }

        return $normalized;
    }
}
