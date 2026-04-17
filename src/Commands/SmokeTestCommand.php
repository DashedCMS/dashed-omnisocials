<?php

namespace Dashed\DashedOmnisocials\Commands;

use Illuminate\Console\Command;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;

class SmokeTestCommand extends Command
{
    protected $signature = 'dashed-omnisocials:smoke-test';

    protected $description = 'Test de Omnisocials API verbinding';

    public function handle(): int
    {
        $client = new OmnisocialsClient();

        if (! $client->isConfigured()) {
            $this->error('Geen Omnisocials API key geconfigureerd.');

            return self::FAILURE;
        }

        $this->info('Verbinding testen...');

        try {
            $accounts = $client->getAccounts();
            $accountList = $accounts['data'] ?? $accounts;

            $this->info('Verbinding geslaagd. ' . count($accountList) . ' account(s) gevonden:');
            $this->newLine();

            foreach ($accountList as $account) {
                $this->line(sprintf(
                    '  - [%s] %s (%s)',
                    $account['id'] ?? '?',
                    $account['name'] ?? $account['username'] ?? 'Onbekend',
                    $account['platform'] ?? 'Onbekend platform',
                ));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Verbinding mislukt: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
