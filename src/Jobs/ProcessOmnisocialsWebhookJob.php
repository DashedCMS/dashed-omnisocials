<?php

namespace Dashed\DashedOmnisocials\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedMarketing\Models\SocialChannel;

class ProcessOmnisocialsWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function handle(): void
    {
        $event = $this->payload['event'] ?? null;
        $postId = $this->payload['post_id'] ?? $this->payload['id'] ?? null;
        $accountId = $this->payload['account_id'] ?? null;

        if (! $event || ! $postId) {
            Log::warning('Omnisocials webhook: missing event or post_id', $this->payload);

            return;
        }

        $siteId = $this->resolveSiteId($accountId);
        if (! $siteId) {
            Log::warning('Omnisocials webhook: could not resolve site_id', ['account_id' => $accountId]);

            return;
        }

        $post = SocialPost::withoutGlobalScopes()
            ->where('external_id', $postId)
            ->where('site_id', $siteId)
            ->first();

        if (! $post) {
            Log::warning('Omnisocials webhook: no SocialPost found', ['external_id' => $postId, 'site_id' => $siteId]);

            return;
        }

        match ($event) {
            'post.published' => $this->handlePublished($post),
            'post.partially_posted' => $this->handlePartiallyPosted($post),
            'post.failed' => $this->handleFailed($post),
            'post.analytics_updated' => $this->handleAnalyticsUpdated($post),
            default => Log::info("Omnisocials webhook: unhandled event '{$event}'"),
        };
    }

    private function handlePublished(SocialPost $post): void
    {
        $post->update([
            'status' => 'posted',
            'posted_at' => now(),
            'post_url' => $this->payload['post_url'] ?? $this->payload['url'] ?? $post->post_url,
            'external_data' => array_merge($post->external_data ?? [], [
                'webhook_published' => $this->payload,
            ]),
        ]);

        Log::info("Omnisocials: post #{$post->id} published successfully");
    }

    private function handlePartiallyPosted(SocialPost $post): void
    {
        $failedPlatforms = $this->payload['failed_platforms'] ?? [];

        $post->update([
            'status' => 'partially_posted',
            'failed_platforms' => $failedPlatforms,
        ]);

        Log::warning("Omnisocials: post #{$post->id} partially posted", [
            'failed_platforms' => $failedPlatforms,
        ]);

        RetryFailedPlatformsJob::dispatch($post)->delay(now()->addSeconds(60));
    }

    private function handleFailed(SocialPost $post): void
    {
        $post->update([
            'status' => 'publish_failed',
        ]);

        Log::warning("Omnisocials: post #{$post->id} failed to publish", [
            'error' => $this->payload['error'] ?? 'Unknown error',
            'payload' => $this->payload,
        ]);
    }

    private function handleAnalyticsUpdated(SocialPost $post): void
    {
        $metrics = $this->payload['metrics'] ?? $this->payload['data'] ?? [];

        $existingData = $post->performance_data ?? [];
        $mergedData = array_merge($existingData, $metrics);

        $post->update([
            'performance_data' => $mergedData,
            'analytics_synced_at' => now(),
        ]);

        Log::info("Omnisocials: analytics updated for post #{$post->id}");
    }

    private function resolveSiteId(?string $accountId): ?string
    {
        if (! $accountId) {
            return null;
        }

        $channel = SocialChannel::withoutGlobalScopes()
            ->where('omnisocials_account_id', $accountId)
            ->first();

        return $channel?->site_id;
    }
}
