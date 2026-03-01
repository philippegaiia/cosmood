<?php

namespace App\Filament\Pages\Settings;

use App\Models\Settings;
use BackedEnum;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
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
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Settings::set('internal_supplier_label', $data['internal_supplier_label']);
        Settings::set('date_format', $data['date_format']);

        Notification::make()
            ->title('Paramètres enregistrés')
            ->success()
            ->send();
    }
}
