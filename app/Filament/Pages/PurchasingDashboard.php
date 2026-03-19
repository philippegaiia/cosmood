<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ActiveWavesWidget;
use App\Filament\Widgets\PendingOrdersWidget;
use App\Filament\Widgets\StockAlertsWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class PurchasingDashboard extends Dashboard implements HasKnowledgeBase
{
    protected static ?string $title = 'Dashboard Achats';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 10;

    protected static string $routePath = '/purchasing-dashboard';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.pilotage');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.purchases');
    }

    public function getWidgets(): array
    {
        return [
            StockAlertsWidget::class,
            PendingOrdersWidget::class,
            ActiveWavesWidget::class,
        ];
    }

    public static function getDocumentation(): array|string
    {
        return [
            'procurement',
            'procurement/procurement-overview',
            'procurement/supplier-orders',
        ];
    }
}
