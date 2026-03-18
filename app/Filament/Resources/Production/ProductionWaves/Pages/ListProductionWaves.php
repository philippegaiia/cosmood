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
                ->label(__('Légende signaux'))
                ->icon(Heroicon::OutlinedInformationCircle)
                ->color('gray')
                ->modalHeading(__('Légende signaux vague'))
                ->modalDescription(__('Couverture appro: vert = prêt sans manque, orange = stock/provisoire à finaliser, rouge = achat à sécuriser. Fabrication sécurisée: packaging exclu, vert = planning fabrication sécurisé, orange = support présent mais encore à finaliser, rouge = fabrication non sécurisée.'))
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
            $service = app(WaveProcurementService::class);
            $coverageSnapshots = $service->getCoverageSnapshotForWaves($waveRecords);
            $fabricationSnapshots = $service->getFabricationSnapshotForWaves($waveRecords);

            $waveRecords->each(function (ProductionWave $wave) use ($coverageSnapshots, $fabricationSnapshots): void {
                /** @var array{label: string, color: string, tooltip: string} $snapshot */
                $snapshot = $coverageSnapshots->get($wave->id, [
                    'label' => __('Sans besoin'),
                    'color' => 'gray',
                    'tooltip' => __('Aucune production liée.'),
                ]);
                /** @var array{label: string, color: string, tooltip: string} $fabricationSnapshot */
                $fabricationSnapshot = $fabricationSnapshots->get($wave->id, [
                    'label' => __('Sans besoin'),
                    'color' => 'gray',
                    'tooltip' => __('Aucune production liée.'),
                ]);

                $wave->setAttribute('coverage_signal_label', $snapshot['label']);
                $wave->setAttribute('coverage_signal_color', $snapshot['color']);
                $wave->setAttribute('coverage_signal_tooltip', $snapshot['tooltip']);
                $wave->setAttribute('fabrication_signal_label', $fabricationSnapshot['label']);
                $wave->setAttribute('fabrication_signal_color', $fabricationSnapshot['color']);
                $wave->setAttribute('fabrication_signal_tooltip', $fabricationSnapshot['tooltip']);
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
