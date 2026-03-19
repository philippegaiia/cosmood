<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ProductionCalendarWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class ProductionCalendar extends Dashboard implements HasKnowledgeBase
{
    protected static ?string $title = 'Calendrier production';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

    protected static string $routePath = '/production-calendar';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.pilotage');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.calendar');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('navigation.items.production');
    }

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

    public static function getDocumentation(): array|string
    {
        return [
            'planning/production-calendar',
            'planning/planning-board',
        ];
    }
}
