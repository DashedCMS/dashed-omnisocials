<?php

namespace Dashed\DashedOmnisocials\Filament\Pages\Settings;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Dashed\DashedOmnisocials\Client\OmnisocialsClient;
use Dashed\DashedOmnisocials\Jobs\SyncOmnisocialsAccountsJob;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;

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
            ->label('Test verbinding')
            ->icon('heroicon-o-signal')
            ->color('info')
            ->action(function () {
                $client = new OmnisocialsClient;

                if (! $client->isConfigured()) {
                    Notification::make()
                        ->title('Geen API key ingesteld')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $accounts = $client->getAccounts();
                    $count = count($accounts['data'] ?? $accounts);

                    Notification::make()
                        ->title('Verbinding geslaagd')
                        ->body("{$count} account(s) gevonden.")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Verbinding mislukt')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function syncAccountsAction(): Action
    {
        return Action::make('syncAccounts')
            ->label('Sync accounts')
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->action(function () {
                SyncOmnisocialsAccountsJob::dispatch();

                Notification::make()
                    ->title('Account sync gestart')
                    ->body('De accounts worden op de achtergrond gesynchroniseerd.')
                    ->success()
                    ->send();
            });
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('API configuratie')
                ->schema([
                    Actions::make([
                        $this->testConnectionAction(),
                        $this->syncAccountsAction(),
                    ]),
                    TextInput::make('omnisocials_api_key')
                        ->label('API key')
                        ->password()
                        ->revealable()
                        ->helperText('De API key van je Omnisocials account.'),
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
            ->title('Omnisocials instellingen opgeslagen')
            ->success()
            ->send();

        redirect(OmnisocialsSettingsPage::getUrl());
    }
}
