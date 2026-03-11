<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ActiveWavesWidget;
use App\Filament\Widgets\PendingOrdersWidget;
use App\Filament\Widgets\StockAlertsWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

class PurchasingDashboard extends Dashboard
{
    protected static ?string $title = 'Dashboard Achats';

    protected static ?string $navigationLabel = 'Dashboard Achats';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 0;

    protected static string $routePath = '/purchasing-dashboard';

    public function getWidgets(): array
    {
        return [
            StockAlertsWidget::class,
            PendingOrdersWidget::class,
            ActiveWavesWidget::class,
        ];
    }
}
