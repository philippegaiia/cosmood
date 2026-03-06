<?php

namespace App\Filament\Resources\Production\ProductionWaves\Schemas;

use App\Enums\WaveStatus;
use App\Models\Production\ProductionWave;
use App\Services\Production\WaveProcurementService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductionWaveForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('wave_tabs')
                    ->columnSpanFull()
                    ->tabs([
                        self::getPlanningTab(),
                        self::getProcurementTab(),
                    ]),
            ]);
    }

    private static function getPlanningTab(): Tab
    {
        return Tab::make('Planification')
            ->schema([
                Section::make('Informations générales')
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
                            ->unique(),
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
                                    ->required(fn (Get $get) => $get('status') !== WaveStatus::Draft->value),
                                DatePicker::make('planned_end_date')
                                    ->label('Date de fin')
                                    ->native(false)
                                    ->weekStartsOnMonday()
                                    ->afterOrEqual('planned_start_date')
                                    ->required(fn (Get $get) => $get('status') !== WaveStatus::Draft->value),
                            ])
                            ->columns(2),
                    ])
                    ->visible(fn (string $operation) => $operation === 'edit'),
            ]);
    }

    private static function getProcurementTab(): Tab
    {
        return Tab::make('Approvisionnement')
            ->hiddenOn('create')
            ->schema([
                Section::make('Vue stricte (non alloué)')
                    ->description('Le besoin restant est calculé sur les quantités non allouées. Les commandes restent indicatives.')
                    ->schema([
                        TextEntry::make('procurement_summary')
                            ->hiddenLabel()
                            ->state(function (?ProductionWave $record): string {
                                if (! $record) {
                                    return '-';
                                }

                                $summary = app(WaveProcurementService::class)->getPlanningSummary($record);

                                return sprintf(
                                    'Besoin restant: %s kg | Déjà commandé: %s kg | Reste à passer: %s kg | Stock dispo (indicatif): %s kg | Manque indicatif: %s kg',
                                    number_format((float) $summary['required_remaining_total'], 3, ',', ' '),
                                    number_format((float) $summary['ordered_total'], 3, ',', ' '),
                                    number_format((float) $summary['to_order_total'], 3, ',', ' '),
                                    number_format((float) $summary['stock_total'], 3, ',', ' '),
                                    number_format((float) $summary['shortage_total'], 3, ',', ' '),
                                );
                            }),
                        RepeatableEntry::make('procurement_lines')
                            ->hiddenLabel()
                            ->state(function (?ProductionWave $record): array {
                                if (! $record) {
                                    return [];
                                }

                                return app(WaveProcurementService::class)
                                    ->getPlanningList($record)
                                    ->map(fn (object $line): array => [
                                        'ingredient' => (string) ($line->ingredient_name ?? '-'),
                                        'need_date' => $line->earliest_need_date
                                            ? \Illuminate\Support\Carbon::parse($line->earliest_need_date)->format('d/m/Y')
                                            : '-',
                                        'required_remaining' => number_format((float) ($line->required_remaining_quantity ?? 0), 3, ',', ' ').' kg',
                                        'to_order' => number_format((float) $line->to_order_quantity, 3, ',', ' ').' kg',
                                        'ordered' => number_format((float) $line->ordered_quantity, 3, ',', ' ').' kg',
                                        'stock' => number_format((float) $line->stock_advisory, 3, ',', ' ').' kg',
                                        'shortage' => number_format((float) $line->advisory_shortage, 3, ',', ' ').' kg',
                                        'last_price' => (float) $line->ingredient_price > 0
                                            ? number_format((float) $line->ingredient_price, 2, ',', ' ').' EUR/kg'
                                            : '-',
                                        'estimated_cost' => $line->estimated_cost !== null
                                            ? number_format((float) $line->estimated_cost, 2, ',', ' ').' EUR'
                                            : '-',
                                    ])
                                    ->values()
                                    ->all();
                            })
                            ->table([
                                TableColumn::make('Ingrédient'),
                                TableColumn::make('Besoin date'),
                                TableColumn::make('Besoin restant'),
                                TableColumn::make('À passer'),
                                TableColumn::make('Déjà commandé'),
                                TableColumn::make('Stock (indicatif)'),
                                TableColumn::make('Manque (indicatif)'),
                                TableColumn::make('Dernier prix'),
                                TableColumn::make('Coût estimé'),
                            ])
                            ->schema([
                                TextEntry::make('ingredient'),
                                TextEntry::make('need_date'),
                                TextEntry::make('required_remaining'),
                                TextEntry::make('to_order'),
                                TextEntry::make('ordered'),
                                TextEntry::make('stock'),
                                TextEntry::make('shortage'),
                                TextEntry::make('last_price'),
                                TextEntry::make('estimated_cost'),
                            ])
                            ->contained(false),
                    ]),
            ]);
    }
}
