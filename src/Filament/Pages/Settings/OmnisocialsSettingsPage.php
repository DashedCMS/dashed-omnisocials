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
use Dashed\DashedOmnisocials\Jobs\RegisterOmnisocialsWebhookJob;

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
            'omnisocials_webhook_url' => Customsetting::get('omnisocials_webhook_url'),
            'omnisocials_webhook_secret' => Customsetting::get('omnisocials_webhook_secret'),
        ]);
    }

    public function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Test verbinding')
            ->icon('heroicon-o-signal')
            ->color('info')
            ->action(function () {
                $client = new OmnisocialsClient();

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

    public function registerWebhookAction(): Action
    {
        return Action::make('registerWebhook')
            ->label('(Her)registreer webhook')
            ->icon('heroicon-o-globe-alt')
            ->color('warning')
            ->action(function () {
                try {
                    $job = new RegisterOmnisocialsWebhookJob();
                    $job->handle();

                    $body = $job->results === []
                        ? 'Geen sites verwerkt.'
                        : implode("\n", $job->results);

                    Notification::make()
                        ->title('Webhook registratie voltooid')
                        ->body($body)
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Webhook registratie mislukt')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
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
                        $this->registerWebhookAction(),
                    ]),
                    TextInput::make('omnisocials_api_key')
                        ->label('API key')
                        ->password()
                        ->revealable()
                        ->helperText('De API key van je Omnisocials account.'),
                    TextInput::make('omnisocials_webhook_url')
                        ->label('Webhook URL')
                        ->disabled()
                        ->placeholder('Wordt automatisch ingesteld na registratie')
                        ->helperText('Dit veld wordt gevuld na het registreren van de webhook.'),
                    TextInput::make('omnisocials_webhook_secret')
                        ->label('Webhook secret')
                        ->password()
                        ->revealable()
                        ->helperText('Wordt automatisch ingesteld bij webhook registratie.'),
                ]),
        ])->statePath('data');
    }

    public function submit(): void
    {
        $formData = $this->form->getState();

        foreach (Sites::getSites() as $site) {
            Customsetting::set('omnisocials_api_key', $formData['omnisocials_api_key'] ?? null, $site['id']);
            Customsetting::set('omnisocials_webhook_url', $formData['omnisocials_webhook_url'] ?? null, $site['id']);
            Customsetting::set('omnisocials_webhook_secret', $formData['omnisocials_webhook_secret'] ?? null, $site['id']);
        }

        Notification::make()
            ->title('Omnisocials instellingen opgeslagen')
            ->success()
            ->send();

        redirect(OmnisocialsSettingsPage::getUrl());
    }
}
