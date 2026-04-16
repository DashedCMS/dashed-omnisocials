<?php

namespace Dashed\DashedOmnisocials\Commands;

use Dashed\DashedOmnisocials\Jobs\SyncOmnisocialsAccountsJob;
use Illuminate\Console\Command;

class SyncAccountsCommand extends Command
{
    protected $signature = 'dashed-omnisocials:sync-accounts';

    protected $description = 'Synchroniseer Omnisocials accounts met social kanalen';

    public function handle(): int
    {
        $this->info('Omnisocials account sync starten...');

        SyncOmnisocialsAccountsJob::dispatchSync();

        $this->info('Sync voltooid.');

        return self::SUCCESS;
    }
}
