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
                Section::make(__('Informations générales'))
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Nom'))
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_default')
                            ->label(__('Modèle par défaut'))
                            ->default(false),
                        Toggle::make('is_active')
                            ->label(__('Actif'))
                            ->default(true),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->columnSpanFull()
                            ->rows(3),
                    ]),
                Section::make(__('Contrôles QC'))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('label')
                                    ->label(__('Contrôle'))
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 6,
                                    ]),
                                Select::make('input_type')
                                    ->label(__('Type de saisie'))
                                    ->options(QcInputType::class)
                                    ->default(QcInputType::Number)
                                    ->required()
                                    ->live()
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 3,
                                    ]),
                                TextInput::make('unit')
                                    ->label(__('Unité'))
                                    ->maxLength(20)
                                    ->placeholder(__('kg, g, pH...'))
                                    ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Number->value)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 1,
                                    ]),
                                TextInput::make('min_value')
                                    ->label(__('Min'))
                                    ->numeric()
                                    ->step(0.001)
                                    ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Number->value)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 1,
                                    ]),
                                TextInput::make('max_value')
                                    ->label(__('Max'))
                                    ->numeric()
                                    ->step(0.001)
                                    ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Number->value)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 1,
                                    ]),
                                TextInput::make('target_value')
                                    ->label(__('Cible'))
                                    ->maxLength(255)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 3,
                                    ]),
                                TagsInput::make('options')
                                    ->label(__('Options'))
                                    ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Select->value)
                                    ->columnSpan([
                                        'default' => 1,
                                        'xl' => 3,
                                    ]),
                                Toggle::make('required')
                                    ->label(__('Obligatoire'))
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
