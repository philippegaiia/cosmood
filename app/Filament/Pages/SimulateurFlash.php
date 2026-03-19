<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class SimulateurFlash extends Page implements HasKnowledgeBase
{
    protected static ?string $title = 'Simulateur Flash';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 3;

    protected static string $routePath = '/simulateur-flash';

    protected string $view = 'filament.pages.simulateur-flash';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.pilotage');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.flash_simulator');
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
            'planning/flash-simulator',
            'planning/production-waves',
            'getting-started/first-production-checklist',
        ];
    }
}
