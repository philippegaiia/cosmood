<?php

namespace App\Filament\Pages\Settings;

use App\Models\Settings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class GeneralSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = 'Paramètres généraux';

    protected static ?string $navigationLabel = 'Paramètres';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 100;

    protected static string $routePath = '/settings/general';

    protected string $view = 'filament.pages.settings.general-settings-page';

    public array $data = [];

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Large;
    }

    public function mount(): void
    {
        $this->form->fill([
            'internal_supplier_label' => Settings::get('internal_supplier_label', 'INT'),
            'date_format' => Settings::get('date_format', 'Y-m-d'),
            'company_name' => Settings::companyName(),
            'company_address' => Settings::companyAddress(),
            'company_vat_number' => Settings::companyVatNumber(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make('Affichage')
                    ->description('Configuration de l\'affichage des données dans l\'application.')
                    ->schema([
                        TextInput::make('internal_supplier_label')
                            ->label('Libellé fournisseur interne')
                            ->helperText('Affiché pour les lots produits en interne (ex: masterbatch).')
                            ->default('INT')
                            ->maxLength(20)
                            ->required(),
                        TextInput::make('date_format')
                            ->label('Format de date')
                            ->helperText('Format PHP pour l\'affichage des dates (ex: Y-m-d, d/m/Y).')
                            ->default('Y-m-d')
                            ->maxLength(20)
                            ->required(),
                    ]),
                Section::make('Société émettrice')
                    ->description('Informations affichées sur les bons de commande fournisseurs.')
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Nom société')
                            ->default(config('app.name'))
                            ->maxLength(255)
                            ->required(),
                        Textarea::make('company_address')
                            ->label('Adresse société')
                            ->helperText('Une ligne par information: rue, code postal, ville, pays...')
                            ->rows(4)
                            ->columnSpanFull(),
                        TextInput::make('company_vat_number')
                            ->label('N° TVA')
                            ->maxLength(50),
                    ])
                    ->columns(2),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Settings::set('internal_supplier_label', $data['internal_supplier_label']);
        Settings::set('date_format', $data['date_format']);
        Settings::set('company_name', $data['company_name']);
        Settings::set('company_address', $data['company_address']);
        Settings::set('company_vat_number', $data['company_vat_number']);

        Notification::make()
            ->title('Paramètres enregistrés')
            ->success()
            ->send();
    }
}
