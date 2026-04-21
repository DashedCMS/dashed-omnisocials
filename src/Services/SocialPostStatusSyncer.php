<?php

namespace Dashed\DashedOmnisocials\Services;

use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Dashed\DashedOmnisocials\Exceptions\OmnisocialsApiException;
use Dashed\DashedOmnisocials\Jobs\RetryFailedPlatformsJob;
use Illuminate\Support\Carbon;
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
        $publishedUrls = $this->normalizePublishedUrls($data['published_urls'] ?? []);

        $result = match ($externalStatus) {
            'posted' => $this->applyPosted($post, $data, $errors, $publishedUrls),
            'failed' => $this->applyFailed($post, $data),
            'scheduled' => $this->applyScheduled($post, $data),
            'draft' => 'noop:pending',
            default => $this->applyUnknown($post, $externalStatus, $data),
        };

        $post->update(['last_status_sync_at' => now()]);

        return $result;
    }

    private function applyPosted(SocialPost $post, array $data, array $errors, array $publishedUrls): string
    {
        if (! empty($errors)) {
            $failedPlatforms = array_keys($errors);

            $update = [
                'status' => 'partially_posted',
                'posted_at' => $post->posted_at ?? now(),
                'post_url' => $post->post_url ?? $this->firstUrl($publishedUrls),
                'failed_platforms' => $failedPlatforms,
                'external_data' => array_merge($post->external_data ?? [], [
                    'last_sync_payload' => $data,
                ]),
            ];

            if (! empty($publishedUrls)) {
                $update['published_urls'] = $publishedUrls;
            }

            $post->update($update);

            RetryFailedPlatformsJob::dispatch($post)->delay(now()->addMinute());

            Log::info("[omnisocials:sync] post #{$post->id} partially posted", ['failed' => $failedPlatforms]);

            return 'updated:partially_posted';
        }

        if ($post->status === 'posted') {
            return 'noop:already-posted';
        }

        $update = [
            'status' => 'posted',
            'posted_at' => $post->posted_at ?? now(),
            'post_url' => $post->post_url ?? $this->firstUrl($publishedUrls),
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ];

        if (! empty($publishedUrls)) {
            $update['published_urls'] = $publishedUrls;
        }

        $post->update($update);

        Log::info("[omnisocials:sync] post #{$post->id} marked posted");

        return 'updated:posted';
    }

    private function applyScheduled(SocialPost $post, array $data): string
    {
        $scheduleAt = $data['schedule_at'] ?? $data['scheduled_at'] ?? null;
        $update = [
            'status' => 'scheduled',
            'external_data' => array_merge($post->external_data ?? [], [
                'last_sync_payload' => $data,
            ]),
        ];

        if ($scheduleAt) {
            try {
                $update['scheduled_at'] = Carbon::parse($scheduleAt);
            } catch (\Throwable $e) {
                Log::warning("[omnisocials:sync] post #{$post->id} invalid schedule_at", ['value' => $scheduleAt]);
            }
        }

        $post->update($update);

        return 'updated:scheduled';
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

        return is_string($first) ? $first : null;
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
