<?php

namespace Dashed\DashedOmnisocials;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedOmnisocials\Commands\SmokeTestCommand;
use Dashed\DashedOmnisocials\Commands\SyncAccountsCommand;
use Dashed\DashedMarketing\Managers\PublishingAdapterRegistry;
use Dashed\DashedOmnisocials\Commands\RefreshAnalyticsCommand;
use Dashed\DashedOmnisocials\Adapters\OmnisocialsPublishAdapter;
use Dashed\DashedOmnisocials\Commands\SyncSocialPostStatusesCommand;
use Dashed\DashedOmnisocials\Filament\Pages\Settings\OmnisocialsSettingsPage;

class DashedOmnisocialsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-omnisocials';

    public function configurePackage(Package $package): void
    {
        $package
            ->hasConfigFile('dashed-omnisocials')
            ->hasCommands([
                SyncAccountsCommand::class,
                SmokeTestCommand::class,
                RefreshAnalyticsCommand::class,
                SyncSocialPostStatusesCommand::class,
            ])
            ->name(self::$name);
    }

    public function bootingPackage(): void
    {
        if (method_exists(cms(), 'registerIntegration')) {
            cms()->registerIntegration([
                'slug' => 'omnisocials',
                'label' => 'Omnisocials',
                'icon' => 'heroicon-o-megaphone',
                'category' => 'social',
                'settings_page' => \Dashed\DashedOmnisocials\Filament\Pages\Settings\OmnisocialsSettingsPage::class,
                'health_check' => fn (?string $siteId = null) => \Dashed\DashedCore\Integrations\IntegrationHealth::fromSettings(['omnisocials_api_key'], $siteId, 'API key ontbreekt'),
                'package' => 'dashed-omnisocials',
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('dashed-omnisocials:refresh-analytics')->dailyAt('03:00');
            $schedule->command('dashed-omnisocials:sync-post-statuses')
                ->everyFifteenMinutes()
                ->withoutOverlapping(10)
                ->runInBackground();
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
