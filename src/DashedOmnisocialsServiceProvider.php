<?php

namespace Dashed\DashedOmnisocials;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedOmnisocials\Commands\SmokeTestCommand;
use Dashed\DashedOmnisocials\Commands\SyncAccountsCommand;
use Dashed\DashedOmnisocials\Commands\RegisterWebhookCommand;
use Dashed\DashedMarketing\Managers\PublishingAdapterRegistry;
use Dashed\DashedOmnisocials\Commands\RefreshAnalyticsCommand;
use Dashed\DashedOmnisocials\Adapters\OmnisocialsPublishAdapter;
use Dashed\DashedOmnisocials\Filament\Pages\Settings\OmnisocialsSettingsPage;

class DashedOmnisocialsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-omnisocials';

    public function configurePackage(Package $package): void
    {
        $package
            ->hasConfigFile('dashed-omnisocials')
            ->hasRoutes('webhooks')
            ->hasCommands([
                SyncAccountsCommand::class,
                SmokeTestCommand::class,
                RegisterWebhookCommand::class,
                RefreshAnalyticsCommand::class,
            ])
            ->name(self::$name);
    }

    public function bootingPackage(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('dashed-omnisocials:refresh-analytics')->dailyAt('03:00');
        });

        PublishingAdapterRegistry::register('omnisocials', OmnisocialsPublishAdapter::class, 'Omnisocials');

        cms()->builder('plugins', [
            new DashedOmnisocialsPlugin(),
        ]);

        cms()->registerSettingsPage(
            OmnisocialsSettingsPage::class,
            'Omnisocials',
            'globe-alt',
            'Omnisocials API koppeling en account synchronisatie'
        );
    }
}
