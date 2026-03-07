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
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->visible(fn (string $operation): bool => $operation === 'edit'),
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
                                    'Besoin restant: %s kg | Couvert (cmd + reçu): %s kg | Reçu stock: %s kg | Reste à sécuriser: %s kg | Manque indicatif: %s kg | Commandé ferme: %s kg | Brouillon PO: %s kg | Engagé PO: %s kg',
                                    number_format((float) $summary['required_remaining_total'], 3, ',', ' '),
                                    number_format((float) ($summary['covered_total'] ?? 0), 3, ',', ' '),
                                    number_format((float) ($summary['received_total'] ?? 0), 3, ',', ' '),
                                    number_format((float) ($summary['to_secure_total'] ?? 0), 3, ',', ' '),
                                    number_format((float) $summary['shortage_total'], 3, ',', ' '),
                                    number_format((float) ($summary['firm_order_total'] ?? 0), 3, ',', ' '),
                                    number_format((float) ($summary['draft_order_total'] ?? 0), 3, ',', ' '),
                                    number_format((float) ($summary['committed_total'] ?? 0), 3, ',', ' '),
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
                                    ->map(function (object $line): array {
                                        $required = (float) ($line->required_remaining_quantity ?? 0);
                                        $toSecure = (float) ($line->to_secure_quantity ?? 0);
                                        $advisoryShortage = (float) ($line->advisory_shortage ?? 0);
                                        $covered = max(0, $required - $toSecure);
                                        $coverageWarning = (string) ($line->coverage_warning ?? '');

                                        if ($advisoryShortage > 0) {
                                            $signal = __('À sécuriser');
                                        } elseif ($toSecure > 0) {
                                            $signal = __('Stock à réserver');
                                        } elseif ($coverageWarning !== '') {
                                            $signal = __('Couverture provisoire');
                                        } else {
                                            $signal = __('Prête');
                                        }

                                        return [
                                            'ingredient' => (string) ($line->ingredient_name ?? '-'),
                                            'need_date' => $line->earliest_need_date
                                                ? \Illuminate\Support\Carbon::parse($line->earliest_need_date)->format('d/m/Y')
                                                : '-',
                                            'required_remaining' => number_format($required, 3, ',', ' ').' kg',
                                            'covered' => number_format($covered, 3, ',', ' ').' kg',
                                            'to_secure' => number_format($toSecure, 3, ',', ' ').' kg',
                                            'signal' => $signal,
                                            'source_hint' => $coverageWarning !== ''
                                                ? $coverageWarning
                                                : __('Couvert par engagement PO ou stock disponible'),
                                            'details' => sprintf(
                                                '%s | %s | %s | %s | %s | %s | %s',
                                                __('À passer: :value', ['value' => number_format((float) ($line->to_order_quantity ?? 0), 3, ',', ' ').' kg']),
                                                __('Commandé ferme: :value', ['value' => number_format((float) ($line->firm_open_order_quantity ?? 0), 3, ',', ' ').' kg']),
                                                __('Brouillon PO: :value', ['value' => number_format((float) ($line->draft_open_order_quantity ?? 0), 3, ',', ' ').' kg']),
                                                __('Reçu stock: :value', ['value' => number_format((float) ($line->received_quantity ?? 0), 3, ',', ' ').' kg']),
                                                __('Engagé: :value', ['value' => number_format((float) ($line->committed_open_order_quantity ?? 0), 3, ',', ' ').' kg']),
                                                __('Provisoire: :value', ['value' => number_format((float) ($line->priority_provisional_quantity ?? 0), 3, ',', ' ').' kg']),
                                                __('Stock indicatif: :value', ['value' => number_format((float) ($line->stock_advisory ?? 0), 3, ',', ' ').' kg']),
                                            ),
                                        ];
                                    })
                                    ->values()
                                    ->all();
                            })
                            ->table([
                                TableColumn::make('Ingrédient'),
                                TableColumn::make('Date besoin'),
                                TableColumn::make('Besoin restant'),
                                TableColumn::make('Couvert'),
                                TableColumn::make('Reste à sécuriser'),
                                TableColumn::make('Signal'),
                                TableColumn::make('Source'),
                                TableColumn::make('Détails'),
                            ])
                            ->schema([
                                TextEntry::make('ingredient'),
                                TextEntry::make('need_date'),
                                TextEntry::make('required_remaining'),
                                TextEntry::make('covered'),
                                TextEntry::make('to_secure'),
                                TextEntry::make('signal'),
                                TextEntry::make('source_hint'),
                                TextEntry::make('details'),
                            ])
                            ->contained(false),
                    ]),
            ]);
    }
}
