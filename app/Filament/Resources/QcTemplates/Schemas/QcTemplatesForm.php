<?php

namespace App\Filament\Resources\QcTemplates\Schemas;

use App\Enums\QcInputType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class QcTemplatesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informations générales')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_default')
                            ->label('Modèle par défaut')
                            ->default(false),
                        Toggle::make('is_active')
                            ->label('Actif')
                            ->default(true),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->columnSpanFull()
                            ->rows(3),
                    ]),
                Section::make('Contrôles QC')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->label('')
                            ->schema([
                                TextInput::make('label')
                                    ->label('Contrôle')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 6,
                                    ]),
                                Select::make('input_type')
                                    ->label('Type de saisie')
                                    ->options(QcInputType::class)
                                    ->default(QcInputType::Number)
                                    ->required()
                                    ->live()
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 3,
                                    ]),
                                TextInput::make('unit')
                                    ->label('Unité')
                                    ->maxLength(20)
                                    ->placeholder('kg, g, pH...')
                                    ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Number->value)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 1,
                                    ]),
                                TextInput::make('min_value')
                                    ->label('Min')
                                    ->numeric()
                                    ->step(0.001)
                                    ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Number->value)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 1,
                                    ]),
                                TextInput::make('max_value')
                                    ->label('Max')
                                    ->numeric()
                                    ->step(0.001)
                                    ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Number->value)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 1,
                                    ]),
                                TextInput::make('target_value')
                                    ->label('Cible')
                                    ->maxLength(255)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 3,
                                    ]),
                                TagsInput::make('options')
                                    ->label('Options')
                                    ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Select->value)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 3,
                                    ]),
                                Toggle::make('required')
                                    ->label('Obligatoire')
                                    ->default(true)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 1,
                                    ]),
                                TextInput::make('stage')
                                    ->default('final_release')
                                    ->hidden(),
                                TextInput::make('sort_order')
                                    ->numeric()
                                    ->default(0)
                                    ->hidden(),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                                'xl' => 12,
                            ])
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->orderColumn('sort_order')
                            ->itemLabel(fn (array $state): string => $state['label'] ?? 'Nouveau contrôle'),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
