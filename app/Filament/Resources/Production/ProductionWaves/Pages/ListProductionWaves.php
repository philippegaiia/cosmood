<?php

namespace App\Filament\Resources\Production\ProductionWaves\Pages;

use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Models\Production\ProductionWave;
use App\Services\Production\WaveProcurementService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

class ListProductionWaves extends ListRecords
{
    protected static string $resource = ProductionWaveResource::class;

    private bool $hasDecoratedTableRecords = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('coverageLegend')
                ->label(__('Légende couverture'))
                ->icon(Heroicon::OutlinedInformationCircle)
                ->color('gray')
                ->modalHeading(__('Légende couverture appro'))
                ->modalDescription(__('Vert: prêt (pas de manque). Orange: partiel (stock/PO/provisoire à finaliser). Rouge: à sécuriser (manque indicatif).'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Fermer')),
            CreateAction::make(),
        ];
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();

        if ($this->hasDecoratedTableRecords) {
            return $records;
        }

        $waveRecords = $this->getWaveTableRecordsCollection($records);

        if ($waveRecords->isNotEmpty()) {
            $coverageSnapshots = app(WaveProcurementService::class)->getCoverageSnapshotForWaves($waveRecords);

            $waveRecords->each(function (ProductionWave $wave) use ($coverageSnapshots): void {
                /** @var array{label: string, color: string, tooltip: string} $snapshot */
                $snapshot = $coverageSnapshots->get($wave->id, [
                    'label' => __('Sans besoin'),
                    'color' => 'gray',
                    'tooltip' => __('Aucune production liée.'),
                ]);

                $wave->setAttribute('coverage_signal_label', $snapshot['label']);
                $wave->setAttribute('coverage_signal_color', $snapshot['color']);
                $wave->setAttribute('coverage_signal_tooltip', $snapshot['tooltip']);
            });
        }

        if (($records instanceof Paginator || $records instanceof CursorPaginator) && method_exists($records, 'setCollection')) {
            $records->setCollection($waveRecords);
        } else {
            $records = $waveRecords;
        }

        $this->hasDecoratedTableRecords = true;

        return $records;
    }

    public function flushCachedTableRecords(): void
    {
        parent::flushCachedTableRecords();

        $this->hasDecoratedTableRecords = false;
    }

    /**
     * @param  Collection<int, ProductionWave>|Paginator|CursorPaginator  $records
     * @return Collection<int, ProductionWave>
     */
    private function getWaveTableRecordsCollection(Collection|Paginator|CursorPaginator $records): Collection
    {
        if (($records instanceof Paginator || $records instanceof CursorPaginator) && method_exists($records, 'getCollection')) {
            /** @var Collection<int, ProductionWave> $records */
            $records = $records->getCollection();
        }

        /** @var Collection<int, ProductionWave> $records */
        return $records
            ->filter(fn (mixed $record): bool => $record instanceof ProductionWave)
            ->values();
    }
}
