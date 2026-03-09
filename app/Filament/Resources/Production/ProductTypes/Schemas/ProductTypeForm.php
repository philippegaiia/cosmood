<?php

namespace App\Filament\Resources\Production\ProductTypes\Schemas;

use App\Enums\SizingMode;
use App\Models\Production\ProductionLine;
use App\Models\Production\ProductType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductTypeForm
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
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug($state))),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->unique()
                            ->helperText('Généré automatiquement à partir du nom, modifiable si besoin.'),
                        Select::make('product_category_id')
                            ->label('Catégorie de produit')
                            ->relationship('productCategory', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('qc_template_id')
                            ->label(__('Modèle QC'))
                            ->relationship(
                                name: 'qcTemplate',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($query) => $query->where('is_active', true),
                            )
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText(__('Choisissez le modèle QC partagé par ce type de produit.')),
                        Select::make('allowed_production_line_ids')
                            ->label(__('Lignes de production autorisées'))
                            ->options(fn (): array => ProductionLine::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->dehydrated(false)
                            ->helperText(__('Définit sur quelles lignes ce type de produit peut être planifié.'))
                            ->afterStateHydrated(function (Select $component, ?ProductType $record): void {
                                if (! $record) {
                                    $component->state([]);

                                    return;
                                }

                                $record->loadMissing('allowedProductionLines');

                                $component->state($record->allowedProductionLines->modelKeys());
                            })
                            ->afterStateUpdated(function (?array $state, Get $get, Set $set): void {
                                $allowedLineIds = self::normalizeProductionLineIds($state ?? []);
                                $defaultLineId = self::normalizeProductionLineId($get('default_production_line_id'));

                                if ($defaultLineId !== null && ! in_array($defaultLineId, $allowedLineIds, true)) {
                                    $set('default_production_line_id', null);
                                    $defaultLineId = null;
                                }

                                if ($defaultLineId === null && count($allowedLineIds) === 1) {
                                    $set('default_production_line_id', $allowedLineIds[0]);
                                }
                            }),
                        Select::make('default_production_line_id')
                            ->label(__('Ligne de production par défaut'))
                            ->options(fn (Get $get): array => ProductionLine::query()
                                ->whereIn('id', self::normalizeProductionLineIds($get('allowed_production_line_ids') ?? []))
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                        Toggle::make('is_active')
                            ->label(__('Actif'))
                            ->default(true),
                    ]),

                Section::make('Paramètres de taille de batch')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
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
                            ->visible(fn (Get $get) => $get('sizing_mode') === SizingMode::FinalMass->value),
                    ]),

                Section::make('Préréglages de taille de batch')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('batchSizePresets')
                            ->hiddenLabel()
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
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                                'xl' => 5,
                            ])
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => $state['name'] ?? 'Nouveau préréglage'),
                    ])
                    ->collapsible(),
            ]);
    }

    /**
     * @param  array<int, int|string|null>  $productionLineIds
     * @return array<int, int>
     */
    private static function normalizeProductionLineIds(array $productionLineIds): array
    {
        return collect($productionLineIds)
            ->filter(fn (mixed $lineId): bool => filled($lineId))
            ->map(fn (mixed $lineId): int => (int) $lineId)
            ->filter(fn (int $lineId): bool => $lineId > 0)
            ->unique()
            ->values()
            ->all();
    }

    private static function normalizeProductionLineId(mixed $productionLineId): ?int
    {
        if (! filled($productionLineId)) {
            return null;
        }

        $normalizedLineId = (int) $productionLineId;

        return $normalizedLineId > 0 ? $normalizedLineId : null;
    }
}
