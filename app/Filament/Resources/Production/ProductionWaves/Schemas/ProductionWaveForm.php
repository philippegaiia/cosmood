<?php

namespace App\Filament\Resources\Production\ProductionWaves\Schemas;

use App\Enums\WaveStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductionWaveForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informations générales')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('status')
                            ->label('Statut')
                            ->options(WaveStatus::class)
                            ->default(WaveStatus::Draft)
                            ->required()
                            ->disabled(fn (string $operation) => $operation === 'edit'),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->columnSpanFull()
                            ->rows(3),
                    ])
                    ->columns(3),

                Section::make('Dates planifiées')
                    ->schema([
                        Fieldset::make('Période')
                            ->schema([
                                DatePicker::make('planned_start_date')
                                    ->label('Date de début')
                                    ->native(false)
                                    ->weekStartsOnMonday()
                                    ->required(fn (callable $get) => $get('status') !== WaveStatus::Draft->value),
                                DatePicker::make('planned_end_date')
                                    ->label('Date de fin')
                                    ->native(false)
                                    ->weekStartsOnMonday()
                                    ->afterOrEqual('planned_start_date')
                                    ->required(fn (callable $get) => $get('status') !== WaveStatus::Draft->value),
                            ])
                            ->columns(2),
                    ])
                    ->visible(fn (string $operation) => $operation === 'edit'),
            ]);
    }
}
