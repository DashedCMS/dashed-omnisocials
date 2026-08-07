<?php

namespace Dashed\DashedOmnisocials\Filament\Pages\Settings;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Dashed\DashedCore\Classes\Sites;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Dashed\DashedOmnisocials\Jobs\SyncOmnisocialsAccountsJob;

class OmnisocialsSettingsPage extends Page implements HasSchemas
{
    use HasSettingsPermission;
    use InteractsWithSchemas;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Omnisocials instellingen';

    protected string $view = 'dashed-core::settings.pages.default-settings';

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'omnisocials_api_key' => Customsetting::get('omnisocials_api_key'),
        ]);
    }

    public function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label(__('Test verbinding'))
            ->icon('heroicon-o-signal')
            ->color('info')
            ->action(function () {
                $client = new OmnisocialsClient();

                if (! $client->isConfigured()) {
                    Notification::make()
                        ->title(__('Geen API key ingesteld'))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $accounts = $client->getAccounts();
                    $count = count($accounts['data'] ?? $accounts);

                    Notification::make()
                        ->title(__('Verbinding geslaagd'))
                        ->body(__(':aantal account(s) gevonden.', ['aantal' => $count]))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('Verbinding mislukt'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function syncAccountsAction(): Action
    {
        return Action::make('syncAccounts')
            ->label(__('Sync accounts'))
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->action(function () {
                SyncOmnisocialsAccountsJob::dispatch();

                Notification::make()
                    ->title(__('Account sync gestart'))
                    ->body(__('De accounts worden op de achtergrond gesynchroniseerd.'))
                    ->success()
                    ->send();
            });
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('API configuratie'))
                ->schema([
                    Actions::make([
                        $this->testConnectionAction(),
                        $this->syncAccountsAction(),
                    ]),
                    TextInput::make('omnisocials_api_key')
                        ->label(__('API key'))
                        ->password()
                        ->revealable()
                        ->helperText(__('De API key van je Omnisocials account.')),
                ]),
        ])->statePath('data');
    }

    public function submit(): void
    {
        $formData = $this->form->getState();

        foreach (Sites::getSites() as $site) {
            Customsetting::set('omnisocials_api_key', $formData['omnisocials_api_key'] ?? null, $site['id']);
        }

        Notification::make()
            ->title(__('Omnisocials instellingen opgeslagen'))
            ->success()
            ->send();

        redirect(OmnisocialsSettingsPage::getUrl());
    }
}
