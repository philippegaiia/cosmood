<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class WaveProcurementOverview extends Page
{
    protected static ?string $title = 'Pilotage achats vagues';

    protected static ?string $navigationLabel = 'Pilotage achats';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?int $navigationSort = 13;

    protected static string $routePath = '/waves-procurement';

    protected string $view = 'filament.pages.wave-procurement-overview';

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }
}
