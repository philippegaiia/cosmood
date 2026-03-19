<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class WaveProcurementOverview extends Page implements HasKnowledgeBase
{
    protected static ?string $title = 'Pilotage appro production';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 1;

    protected static string $routePath = '/waves-procurement';

    protected string $view = 'filament.pages.wave-procurement-overview';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.pilotage');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.procurement_overview');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('navigation.items.purchases');
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public static function getDocumentation(): array|string
    {
        return [
            'procurement/procurement-overview',
            'procurement/supplier-orders',
            'stock-and-allocations/allocations',
        ];
    }
}
