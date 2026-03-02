<?php

namespace App\Filament\Resources\Holidays\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class HolidayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Informations du jour férié'))
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('Date'))
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        TextInput::make('name')
                            ->label(__('Nom'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('ex: Noël, Jour de l\'an')),

                        Toggle::make('is_recurring')
                            ->label(__('Jour férié récurrent'))
                            ->helperText(__('Cochez si ce jour férié se répète chaque année (ex: Noël, 1er janvier)'))
                            ->default(false),
                    ])
                    ->columns(2),
            ]);
    }
}
