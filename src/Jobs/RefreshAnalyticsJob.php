<?php

namespace Dashed\DashedOmnisocials\Jobs;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshAnalyticsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ?SocialPost $post = null,
    ) {
    }

    public function handle(): void
    {
        if ($this->post) {
            $this->refreshPost($this->post);

            return;
        }

        $posts = SocialPost::withoutGlobalScopes()
            ->whereNotNull('external_id')
            ->where('posted_at', '>', now()->subDays(30))
            ->get();

        foreach ($posts as $post) {
            $this->refreshPost($post);
        }
    }

    private function refreshPost(SocialPost $post): void
    {
        try {
            $client = new OmnisocialsClient($post->site_id ?? null);

            if (! $client->isConfigured()) {
                return;
            }

            $analytics = $client->getPostAnalytics($post->external_id);

            $post->update([
                'performance_data' => array_merge($post->performance_data ?? [], $analytics),
                'analytics_synced_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Analytics refresh failed for post', [
                'post_id' => $post->id,
                'external_id' => $post->external_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
