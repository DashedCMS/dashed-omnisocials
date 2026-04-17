<?php

namespace Dashed\DashedOmnisocials\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedMarketing\Models\SocialChannel;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Dashed\DashedOmnisocials\Support\ChannelPlatformMapper;

class SyncOmnisocialsAccountsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
    }

    public function handle(): void
    {
        foreach (Sites::getSites() as $site) {
            $siteId = $site['id'];
            $client = new OmnisocialsClient($siteId);

            if (! $client->isConfigured()) {
                continue;
            }

            try {
                $accounts = $client->getAccounts();
                $accountList = $accounts['data'] ?? $accounts;

                // Cache the accounts in Customsetting
                Customsetting::set('omnisocials_accounts', json_encode($accountList), $siteId);

                // Auto-map accounts to channels where omnisocials_account_id is null
                $channels = SocialChannel::withoutGlobalScopes()
                    ->where('site_id', $siteId)
                    ->whereNull('omnisocials_account_id')
                    ->get();

                foreach ($channels as $channel) {
                    $platform = ChannelPlatformMapper::toOmnisocials($channel->slug);

                    if (! $platform) {
                        continue;
                    }

                    // Find a matching account by platform
                    $matchingAccount = collect($accountList)->first(function ($account) use ($platform) {
                        return ($account['platform'] ?? null) === $platform;
                    });

                    if ($matchingAccount) {
                        $channel->update([
                            'omnisocials_account_id' => $matchingAccount['id'] ?? null,
                            'omnisocials_platform' => $platform,
                        ]);

                        Log::info('Auto-mapped Omnisocials account', [
                            'channel' => $channel->slug,
                            'account_id' => $matchingAccount['id'] ?? null,
                            'platform' => $platform,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Omnisocials account sync failed', [
                    'site_id' => $siteId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
