<?php

namespace App\Filament\Widgets;

use App\Enums\WaveStatus;
use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Models\Production\ProductionWave;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Active Waves Widget.
 *
 * Shows production waves that are currently active:
 * - Status = Approved or InProgress
 * - Shows progress (productions completed/total)
 * - Shows supply coverage status
 *
 * Quick action to view wave details.
 */
class ActiveWavesWidget extends BaseWidget
{
    protected static ?string $heading = 'Vagues en cours';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 6,
        'lg' => 6,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductionWave::query()
                    ->withCount('productions')
                    ->with(['productions' => function ($query): void {
                        $query->where('status', \App\Enums\ProductionStatus::Finished);
                    }])
                    ->whereIn('status', [WaveStatus::Approved, WaveStatus::InProgress])
                    ->orderBy('planned_start_date')
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('Vague'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Statut'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('coverage')
                    ->label(__('Couverture'))
                    ->badge()
                    ->state(fn (ProductionWave $record): string => $record->getCoverageSignalLabel())
                    ->color(fn (ProductionWave $record): string => $record->getCoverageSignalColor()),

                TextColumn::make('progress')
                    ->label(__('Progression'))
                    ->state(function (ProductionWave $record): string {
                        $completed = $record->productions->count();
                        $total = $record->productions_count;

                        return "{$completed}/{$total}";
                    })
                    ->color(function (ProductionWave $record): string {
                        $completed = $record->productions->count();
                        $total = $record->productions_count;

                        if ($total === 0) {
                            return 'gray';
                        }

                        $percentage = ($completed / $total) * 100;

                        return match (true) {
                            $percentage >= 75 => 'success',
                            $percentage >= 50 => 'warning',
                            $percentage >= 25 => 'amber',
                            default => 'danger',
                        };
                    }),
            ])
            ->recordUrl(fn (ProductionWave $record): string => ProductionWaveResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading(__('Aucune vague active'))
            ->emptyStateDescription(__('Créez une vague de production pour commencer.'))
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
