<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ProductionCalendarWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

class ProductionCalendar extends Dashboard
{
    protected static ?string $title = 'Calendrier production';

    protected static ?string $navigationLabel = 'Calendrier';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?int $navigationSort = 11;

    protected static string $routePath = '/production-calendar';

    public function getWidgets(): array
    {
        return [
            ProductionCalendarWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
