<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class PlanningBoard extends Page
{
    protected static ?string $title = 'Planning production';

    protected static ?string $navigationLabel = 'Planning board';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?int $navigationSort = 12;

    protected static string $routePath = '/planning-board';

    protected string $view = 'filament.pages.planning-board';

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }
}
