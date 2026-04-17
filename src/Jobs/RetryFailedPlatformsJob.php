<?php

namespace Dashed\DashedOmnisocials\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedMarketing\Models\SocialPost;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;

class RetryFailedPlatformsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 1800];

    public function __construct(public SocialPost $post)
    {
    }

    public function handle(): void
    {
        if (! $this->post->external_id || empty($this->post->failed_platforms)) {
            return;
        }

        $client = new OmnisocialsClient($this->post->site_id);
        $client->retryFailedPlatforms($this->post->external_id);
        $this->post->increment('retry_count');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Omnisocials retry exhausted for post #{$this->post->id}", [
            'failed_platforms' => $this->post->failed_platforms,
            'retry_count' => $this->post->retry_count,
            'error' => $exception->getMessage(),
        ]);
    }
}
