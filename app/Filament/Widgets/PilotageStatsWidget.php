<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\ProductionStatus;
use App\Enums\WaveStatus;
use App\Filament\Pages\PlanningBoard;
use App\Filament\Pages\ProductionDashboard;
use App\Filament\Pages\PurchasingDashboard;
use App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierOrder;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class PilotageStatsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        return [
            Stat::make(__('Productions aujourd\'hui'), (string) $this->countTodaysProductions())
                ->description(__('Batches planifiés ou en cours'))
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->url(PlanningBoard::getUrl())
                ->color('info'),
            Stat::make(__('À lancer'), (string) $this->countReadyToStartProductions())
                ->description(__('Productions confirmées cette semaine'))
                ->descriptionIcon(Heroicon::OutlinedPlay)
                ->url(ProductionDashboard::getUrl())
                ->color('warning'),
            Stat::make(__('En cours'), (string) $this->countOngoingProductions())
                ->description(__('Fabrications ouvertes'))
                ->descriptionIcon(Heroicon::OutlinedArrowPath)
                ->url(ProductionDashboard::getUrl())
                ->color('success'),
            Stat::make(__('Tâches du jour'), (string) $this->countTodaysTasks())
                ->description(__('Tâches non terminées'))
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentList)
                ->url(ProductionDashboard::getUrl())
                ->color('gray'),
            Stat::make(__('Alertes stock'), (string) $this->countStockAlerts())
                ->description(__('Ingrédients sous minimum'))
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->url(PurchasingDashboard::getUrl())
                ->color('danger'),
            Stat::make(__('Commandes en attente'), (string) $this->countPendingOrders())
                ->description(__('Achats à suivre'))
                ->descriptionIcon(Heroicon::OutlinedShoppingCart)
                ->url(PurchasingDashboard::getUrl())
                ->color('warning'),
            Stat::make(__('Vagues actives'), (string) $this->countActiveWaves())
                ->description(__('Approuvées ou en cours'))
                ->descriptionIcon(Heroicon::OutlinedQueueList)
                ->url(ProductionWaveResource::getUrl('index'))
                ->color('info'),
        ];
    }

    private function countTodaysProductions(): int
    {
        return Production::query()
            ->whereDate('production_date', today())
            ->whereIn('status', [
                ProductionStatus::Planned,
                ProductionStatus::Confirmed,
                ProductionStatus::Ongoing,
            ])
            ->count();
    }

    private function countReadyToStartProductions(): int
    {
        return Production::query()
            ->where('status', ProductionStatus::Confirmed)
            ->whereHas('productionItems', function (Builder $query): void {
                $query->whereHas('allocations');
            })
            ->whereDate('production_date', '>=', now())
            ->whereDate('production_date', '<=', now()->addDays(7))
            ->count();
    }

    private function countOngoingProductions(): int
    {
        return Production::query()
            ->where('status', ProductionStatus::Ongoing)
            ->count();
    }

    private function countTodaysTasks(): int
    {
        return ProductionTask::query()
            ->whereDate('scheduled_date', today())
            ->where('is_finished', false)
            ->whereNull('cancelled_at')
            ->count();
    }

    private function countStockAlerts(): int
    {
        return Ingredient::query()
            ->whereNotNull('stock_min')
            ->where('stock_min', '>', 0)
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $ingredient->getTotalAvailableStock() < $ingredient->stock_min)
            ->count();
    }

    private function countPendingOrders(): int
    {
        return SupplierOrder::query()
            ->whereIn('order_status', [
                OrderStatus::Passed,
                OrderStatus::Confirmed,
                OrderStatus::Delivered,
            ])
            ->count();
    }

    private function countActiveWaves(): int
    {
        return ProductionWave::query()
            ->whereIn('status', [WaveStatus::Approved, WaveStatus::InProgress])
            ->count();
    }
}
