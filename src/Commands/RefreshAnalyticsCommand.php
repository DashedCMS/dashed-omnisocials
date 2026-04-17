<?php

namespace Dashed\DashedOmnisocials\Commands;

use Illuminate\Console\Command;
use Dashed\DashedOmnisocials\Jobs\RefreshAnalyticsJob;

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
