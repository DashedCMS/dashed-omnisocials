<?php

namespace Dashed\DashedOmnisocials\Commands;

use Dashed\DashedOmnisocials\Jobs\RefreshAnalyticsJob;
use Illuminate\Console\Command;

class RefreshAnalyticsCommand extends Command
{
    protected $signature = 'dashed-omnisocials:refresh-analytics';

    protected $description = 'Ververs analytics voor recente social posts via Omnisocials';

    public function handle(): int
    {
        $this->info('Analytics refresh starten...');

        RefreshAnalyticsJob::dispatchSync();

        $this->info('Analytics refresh voltooid.');

        return self::SUCCESS;
    }
}
