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
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class HomeDashboard extends Dashboard implements HasKnowledgeBase
{
    protected static ?string $title = 'Pilotage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -10;

    protected static string $routePath = '/';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.pilotage');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.pilotage');
    }

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

    public static function getDocumentation(): array|string
    {
        return [
            'getting-started',
            'planning',
            'procurement/procurement-overview',
        ];
    }
}
