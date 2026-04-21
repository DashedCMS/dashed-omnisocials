<?php

namespace Dashed\DashedOmnisocials\Services;

use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Dashed\DashedOmnisocials\Exceptions\OmnisocialsApiException;
use Dashed\DashedOmnisocials\Jobs\RetryFailedPlatformsJob;
use Illuminate\Support\Facades\Log;

class SocialPostStatusSyncer
{
    public function syncPost(SocialPost $post): string
    {
        if (! $post->external_id) {
            return 'skipped:no-external-id';
        }

        $client = new OmnisocialsClient($post->site_id);

        try {
            $response = $client->getPost($post->external_id);
        } catch (OmnisocialsApiException $e) {
            Log::warning("[omnisocials:sync] API error for post #{$post->id}", [
                'external_id' => $post->external_id,
                'error' => $e->getMessage(),
            ]);
            $post->update(['last_status_sync_at' => now()]);

            return 'error:api';
        }

        $data = $response['data'] ?? $response;
        $externalStatus = $data['status'] ?? null;
        $errors = $data['errors'] ?? [];
        $publishedUrls = $data['published_urls'] ?? [];

        $result = match ($externalStatus) {
            'posted' => $this->applyPosted($post, $data, $errors, $publishedUrls),
            'failed' => $this->applyFailed($post, $data),
            'scheduled', 'draft' => 'noop:pending',
            default => $this->applyUnknown($post, $externalStatus, $data),
        };

        $post->update(['last_status_sync_at' => now()]);

        return $result;
    }

    private function applyPosted(SocialPost $post, array $data, array $errors, array $publishedUrls): string
    {
        if (! empty($errors)) {
            $failedPlatforms = array_keys($errors);

            $post->update([
                'status' => 'partially_posted',
                'posted_at' => $post->posted_at ?? now(),
                'post_url' => $post->post_url ?? $this->firstUrl($publishedUrls),
                'failed_platforms' => $failedPlatforms,
                'external_data' => array_merge($post->external_data ?? [], [
                    'last_sync_payload' => $data,
                ]),
            ]);

            RetryFailedPlatformsJob::dispatch($post)->delay(now()->addMinute());

            Log::info("[omnisocials:sync] post #{$post->id} partially posted", ['failed' => $failedPlatforms]);

            return 'updated:partially_posted';
        }

        if ($post->status === 'posted') {
            return 'noop:already-posted';
        }

        $post->update([
            'status' => 'posted',
            'posted_at' => $post->posted_at ?? now(),
            'post_url' => $post->post_url ?? $this->firstUrl($publishedUrls),
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ]);

        Log::info("[omnisocials:sync] post #{$post->id} marked posted");

        return 'updated:posted';
    }

    private function applyFailed(SocialPost $post, array $data): string
    {
        if ($post->status === 'publish_failed') {
            return 'noop:already-failed';
        }

        $post->update([
            'status' => 'publish_failed',
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ]);

        Log::warning("[omnisocials:sync] post #{$post->id} marked failed", ['errors' => $data['errors'] ?? null]);

        return 'updated:failed';
    }

    private function applyUnknown(SocialPost $post, ?string $externalStatus, array $data): string
    {
        Log::info("[omnisocials:sync] post #{$post->id} unknown external status", [
            'external_status' => $externalStatus,
        ]);

        $post->update([
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ]);

        return 'noop:unknown-status';
    }

    private function firstUrl(array $publishedUrls): ?string
    {
        if (empty($publishedUrls)) {
            return null;
        }

        $first = reset($publishedUrls);

        if (is_array($first)) {
            return $first['url'] ?? null;
        }

        return is_string($first) ? $first : null;
    }
}
