<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SimulateurFlash extends Page
{
    protected static ?string $title = 'Simulateur Flash';

    protected static ?string $navigationLabel = 'Simulateur Flash';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?int $navigationSort = 12;

    protected static string $routePath = '/simulateur-flash';

    protected string $view = 'filament.pages.simulateur-flash';
}
