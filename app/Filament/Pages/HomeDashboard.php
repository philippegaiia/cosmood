<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ActiveWavesWidget;
use App\Filament\Widgets\PendingOrdersWidget;
use App\Filament\Widgets\PilotageStatsWidget;
use App\Filament\Widgets\ReadyToStartProductionsWidget;
use App\Filament\Widgets\StockAlertsWidget;
use App\Filament\Widgets\TodaysTasksWidget;
use App\Filament\Widgets\UrgencesWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

class HomeDashboard extends Dashboard
{
    protected static ?string $title = 'Pilotage';

    protected static ?string $navigationLabel = 'Pilotage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -10;

    protected static string $routePath = '/';

    public function getWidgets(): array
    {
        return [
            PilotageStatsWidget::class,
            UrgencesWidget::class,
            ReadyToStartProductionsWidget::class,
            TodaysTasksWidget::class,
            ActiveWavesWidget::class,
            PendingOrdersWidget::class,
            StockAlertsWidget::class,
        ];
    }
}
