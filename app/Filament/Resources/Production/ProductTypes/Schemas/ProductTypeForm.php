<?php

namespace App\Filament\Resources\Production\ProductTypes\Schemas;

use App\Enums\SizingMode;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductTypeForm
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
                        Select::make('product_category_id')
                            ->label('Catégorie de produit')
                            ->relationship('productCategory', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Toggle::make('is_active')
                            ->label('Actif')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Paramètres de taille de batch')
                    ->schema([
                        Select::make('sizing_mode')
                            ->label('Mode de calcul')
                            ->options(SizingMode::class)
                            ->default(SizingMode::OilWeight)
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('default_batch_size')
                            ->label('Taille de batch par défaut (kg)')
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->suffix('kg'),
                        TextInput::make('expected_units_output')
                            ->label('Unités attendues')
                            ->numeric()
                            ->default(0)
                            ->required(),
                        TextInput::make('expected_waste_kg')
                            ->label('Perte estimée (kg)')
                            ->numeric()
                            ->suffix('kg'),
                        TextInput::make('unit_fill_size')
                            ->label('Taille de remplissage unitaire (g)')
                            ->numeric()
                            ->suffix('g')
                            ->visible(fn (callable $get) => $get('sizing_mode') === SizingMode::FinalMass->value),
                    ])
                    ->columns(3),

                Section::make('Préréglages de taille de batch')
                    ->schema([
                        Repeater::make('batchSizePresets')
                            ->label('')
                            ->relationship()
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nom')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('batch_size')
                                    ->label('Taille (kg)')
                                    ->numeric()
                                    ->required()
                                    ->suffix('kg'),
                                TextInput::make('expected_units')
                                    ->label('Unités attendues')
                                    ->numeric()
                                    ->required(),
                                TextInput::make('expected_waste_kg')
                                    ->label('Perte (kg)')
                                    ->numeric()
                                    ->suffix('kg'),
                                Toggle::make('is_default')
                                    ->label('Par défaut')
                                    ->default(false),
                            ])
                            ->columns(5)
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => $state['name'] ?? 'Nouveau préréglage'),
                    ])
                    ->collapsible(),
            ]);
    }
}
