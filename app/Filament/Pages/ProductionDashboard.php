<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ActiveWavesWidget;
use App\Filament\Widgets\ProductionsSoonReadyWidget;
use App\Filament\Widgets\ReadyToStartProductionsWidget;
use App\Filament\Widgets\TodaysTasksWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

class ProductionDashboard extends Dashboard
{
    protected static ?string $title = 'Dashboard Production';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 0;

    protected static string $routePath = '/production-dashboard';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.pilotage');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.production');
    }

    public function getWidgets(): array
    {
        return [
            ReadyToStartProductionsWidget::class,
            TodaysTasksWidget::class,
            ProductionsSoonReadyWidget::class,
            ActiveWavesWidget::class,
        ];
    }
}
