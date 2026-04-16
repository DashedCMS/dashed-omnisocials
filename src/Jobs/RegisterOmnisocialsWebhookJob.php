<?php

namespace Dashed\DashedOmnisocials\Jobs;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RegisterOmnisocialsWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const SUBSCRIBED_EVENTS = [
        'post.published',
        'post.partially_posted',
        'post.failed',
        'post.analytics_updated',
    ];

    public function __construct()
    {
    }

    public function handle(): void
    {
        $webhookUrl = route('omnisocials.webhook');

        foreach (Sites::getSites() as $site) {
            $siteId = $site['id'];
            $client = new OmnisocialsClient($siteId);

            if (! $client->isConfigured()) {
                continue;
            }

            try {
                $this->registerForSite($client, $siteId, $webhookUrl);
            } catch (\Throwable $e) {
                Log::error('Omnisocials webhook registration failed', [
                    'site_id' => $siteId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function registerForSite(OmnisocialsClient $client, string $siteId, string $webhookUrl): void
    {
        $existingWebhooks = $client->getWebhooks();
        $webhookList = $existingWebhooks['data'] ?? $existingWebhooks;

        $matchingWebhook = collect($webhookList)->first(function ($webhook) use ($webhookUrl) {
            return ($webhook['url'] ?? null) === $webhookUrl;
        });

        if ($matchingWebhook) {
            $webhookId = $matchingWebhook['id'];
            $client->updateWebhook($webhookId, [
                'url' => $webhookUrl,
                'events' => self::SUBSCRIBED_EVENTS,
                'active' => true,
            ]);

            Log::info('Omnisocials webhook updated', [
                'site_id' => $siteId,
                'webhook_id' => $webhookId,
            ]);
        } else {
            $response = $client->createWebhook([
                'url' => $webhookUrl,
                'events' => self::SUBSCRIBED_EVENTS,
            ]);

            if (isset($response['secret'])) {
                Customsetting::set('omnisocials_webhook_secret', $response['secret'], $siteId);
            }

            Log::info('Omnisocials webhook created', [
                'site_id' => $siteId,
                'webhook_id' => $response['id'] ?? null,
            ]);
        }
    }
}
