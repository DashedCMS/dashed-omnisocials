<?php

namespace Dashed\DashedOmnisocials;

use Dashed\DashedOmnisocials\Filament\Pages\Settings\OmnisocialsSettingsPage;
use Filament\Contracts\Plugin;
use Filament\Panel;

class DashedOmnisocialsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-omnisocials';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            OmnisocialsSettingsPage::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
    }
}
