<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class PlanningBoard extends Page implements HasKnowledgeBase
{
    protected static ?string $title = 'Planning production';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 1;

    protected static string $routePath = '/planning-board';

    protected string $view = 'filament.pages.planning-board';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.pilotage');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.planning');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('navigation.items.production');
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public static function getDocumentation(): array|string
    {
        return [
            'planning/planning-board',
            'planning/production-waves',
            'settings/production-lines',
        ];
    }
}
