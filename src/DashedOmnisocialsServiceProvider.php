<?php

namespace Dashed\DashedOmnisocials;

use Dashed\DashedMarketing\Managers\PublishingAdapterRegistry;
use Dashed\DashedOmnisocials\Adapters\OmnisocialsPublishAdapter;
use Dashed\DashedOmnisocials\Commands\RegisterWebhookCommand;
use Dashed\DashedOmnisocials\Commands\SmokeTestCommand;
use Dashed\DashedOmnisocials\Commands\SyncAccountsCommand;
use Dashed\DashedOmnisocials\Filament\Pages\Settings\OmnisocialsSettingsPage;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
            ])
            ->name(self::$name);
    }

    public function bootingPackage(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

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
