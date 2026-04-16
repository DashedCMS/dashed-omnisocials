<?php

namespace Dashed\DashedOmnisocials\Commands;

use Dashed\DashedOmnisocials\Jobs\RegisterOmnisocialsWebhookJob;
use Illuminate\Console\Command;

class RegisterWebhookCommand extends Command
{
    protected $signature = 'dashed-omnisocials:register-webhook';

    protected $description = 'Registreer of update de Omnisocials webhook voor alle sites';

    public function handle(): int
    {
        $this->info('Omnisocials webhook registratie starten...');

        RegisterOmnisocialsWebhookJob::dispatchSync();

        $this->info('Webhook registratie voltooid.');

        return self::SUCCESS;
    }
}
