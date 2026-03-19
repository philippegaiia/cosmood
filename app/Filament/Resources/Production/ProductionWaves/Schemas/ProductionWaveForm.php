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
use Illuminate\Support\Carbon;
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
        return Tab::make(__('Planification'))
            ->schema([
                Section::make(__('Informations générales'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Nom'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug($state))),
                        TextInput::make('slug')
                            ->label(__('Slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(),
                        Select::make('status')
                            ->label(__('Statut'))
                            ->options(WaveStatus::class)
                            ->default(WaveStatus::Draft)
                            ->required()
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->visible(fn (string $operation): bool => $operation === 'edit'),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->columnSpanFull()
                            ->rows(3),
                    ])
                    ->columns(3),

                Section::make(__('Dates planifiées'))
                    ->schema([
                        Fieldset::make(__('Période'))
                            ->schema([
                                DatePicker::make('planned_start_date')
                                    ->label(__('Date de début'))
                                    ->native(false)
                                    ->weekStartsOnMonday()
                                    ->required(fn (Get $get) => $get('status') !== WaveStatus::Draft->value),
                                DatePicker::make('planned_end_date')
                                    ->label(__('Date de fin'))
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
        return Tab::make(__('Approvisionnement'))
            ->hiddenOn('create')
            ->schema([
                Section::make(__('Lecture vague'))
                    ->description(__('Vue claire par vague: besoin total, stock disponible, réserve gardée pour urgence, stock mobilisable pour la vague et reste à commander. La date de besoin correspond à J-7 avant le début de vague.'))
                    ->schema([
                        TextEntry::make('procurement_summary')
                            ->hiddenLabel()
                            ->state(function (?ProductionWave $record): string {
                                if (! $record) {
                                    return '-';
                                }

                                $service = app(WaveProcurementService::class);
                                $lines = $service->getPlanningList($record);

                                return implode(' | ', [
                                    __('Besoin total: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'total_wave_requirement')]),
                                    __('Déjà alloué: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'allocated_quantity')]),
                                    __('Besoin restant à couvrir: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'remaining_requirement')]),
                                    __('Stock disponible: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'available_stock')]),
                                    __('Réserve stock: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'reserved_stock_quantity')]),
                                    __('Stock vague: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'planned_stock_quantity')]),
                                    __('Commandé pour cette vague: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'wave_ordered_quantity')]),
                                    __('Reçu pour cette vague: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'wave_received_quantity')]),
                                    __('Commandes ouvertes non engagées: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'open_orders_not_committed')]),
                                    __('Reste à sécuriser: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'remaining_to_secure')]),
                                    __('Reste à commander: :value', ['value' => $service->formatPlanningQuantityByUnit($lines, 'remaining_to_order')]),
                                ]);
                            }),
                        RepeatableEntry::make('procurement_lines')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->extraAttributes([
                                'class' => 'max-w-full overflow-x-auto [&>table]:min-w-[96rem] [&>table]:table-fixed',
                            ])
                            ->state(function (?ProductionWave $record): array {
                                if (! $record) {
                                    return [];
                                }

                                return app(WaveProcurementService::class)
                                    ->getPlanningList($record)
                                    ->map(function (object $line): array {
                                        $service = app(WaveProcurementService::class);
                                        $displayUnit = (string) ($line->display_unit ?? 'kg');
                                        $totalRequirement = (float) ($line->total_wave_requirement ?? 0);
                                        $allocatedQuantity = (float) ($line->allocated_quantity ?? 0);
                                        $remainingRequirement = (float) ($line->remaining_requirement ?? 0);
                                        $availableStock = (float) ($line->available_stock ?? 0);
                                        $reservedStock = (float) ($line->reserved_stock_quantity ?? 0);
                                        $plannedStock = (float) ($line->planned_stock_quantity ?? 0);
                                        $waveOrderedQuantity = (float) ($line->wave_ordered_quantity ?? 0);
                                        $waveReceivedQuantity = (float) ($line->wave_received_quantity ?? 0);
                                        $openOrdersNotCommitted = (float) ($line->open_orders_not_committed ?? 0);
                                        $remainingToSecure = (float) ($line->remaining_to_secure ?? 0);
                                        $remainingToOrder = (float) ($line->remaining_to_order ?? 0);

                                        if ($remainingToOrder > 0) {
                                            $signal = __('À commander');
                                        } elseif ($remainingToSecure > 0) {
                                            $signal = __('À engager');
                                        } elseif ($remainingRequirement <= 0) {
                                            $signal = __('OK');
                                        } elseif ($availableStock > 0) {
                                            $signal = __('À affecter');
                                        } else {
                                            $signal = __('Sécurisé');
                                        }

                                        return [
                                            'ingredient' => (string) ($line->ingredient_name ?? '-'),
                                            'need_date' => $line->need_date
                                                ? Carbon::parse($line->need_date)->format('d/m/Y')
                                                : '-',
                                            'total_requirement' => $service->formatPlanningQuantity($totalRequirement, $displayUnit),
                                            'allocated_quantity' => $service->formatPlanningQuantity($allocatedQuantity, $displayUnit),
                                            'remaining_requirement' => $service->formatPlanningQuantity($remainingRequirement, $displayUnit),
                                            'available_stock' => $service->formatPlanningQuantity($availableStock, $displayUnit),
                                            'reserved_stock_quantity' => $service->formatPlanningQuantity($reservedStock, $displayUnit),
                                            'planned_stock_quantity' => $service->formatPlanningQuantity($plannedStock, $displayUnit),
                                            'wave_ordered_quantity' => $service->formatPlanningQuantity($waveOrderedQuantity, $displayUnit),
                                            'wave_received_quantity' => $service->formatPlanningQuantity($waveReceivedQuantity, $displayUnit),
                                            'open_orders_not_committed' => $service->formatPlanningQuantity($openOrdersNotCommitted, $displayUnit),
                                            'remaining_to_secure' => $service->formatPlanningQuantity($remainingToSecure, $displayUnit),
                                            'remaining_to_order' => $service->formatPlanningQuantity($remainingToOrder, $displayUnit),
                                            'signal' => $signal,
                                        ];
                                    })
                                    ->values()
                                    ->all();
                            })
                            ->table(self::getProcurementTableColumns())
                            ->schema([
                                TextEntry::make('ingredient'),
                                TextEntry::make('need_date'),
                                TextEntry::make('total_requirement'),
                                TextEntry::make('allocated_quantity'),
                                TextEntry::make('remaining_requirement'),
                                TextEntry::make('available_stock'),
                                TextEntry::make('reserved_stock_quantity'),
                                TextEntry::make('planned_stock_quantity'),
                                TextEntry::make('wave_ordered_quantity'),
                                TextEntry::make('wave_received_quantity'),
                                TextEntry::make('open_orders_not_committed'),
                                TextEntry::make('remaining_to_secure'),
                                TextEntry::make('remaining_to_order'),
                                TextEntry::make('signal'),
                            ])
                            ->contained(false),
                    ]),
            ]);
    }

    /**
     * @return array<int, TableColumn>
     */
    private static function getProcurementTableColumns(): array
    {
        return [
            TableColumn::make(__('Ingrédient'))
                ->width('12rem')
                ->wrapHeader(),
            TableColumn::make(__('Date besoin'))
                ->width('7rem')
                ->wrapHeader(),
            TableColumn::make(__('Besoin'))
                ->width('7rem')
                ->wrapHeader(),
            TableColumn::make(__('Alloué'))
                ->width('7rem')
                ->wrapHeader(),
            TableColumn::make(__('Restant'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Stock dispo'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Réserve'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Stock vague'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Cmd vague'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Reçu vague'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('PO non engagées'))
                ->width('9rem')
                ->wrapHeader(),
            TableColumn::make(__('À sécuriser'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('À commander'))
                ->width('8rem')
                ->wrapHeader(),
            TableColumn::make(__('Signal'))
                ->width('7rem')
                ->wrapHeader(),
        ];
    }
}
