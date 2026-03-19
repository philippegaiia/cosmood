<?php

namespace App\Filament\Resources\QcTemplates;

use App\Filament\Resources\QcTemplates\Pages\CreateQcTemplates;
use App\Filament\Resources\QcTemplates\Pages\EditQcTemplates;
use App\Filament\Resources\QcTemplates\Pages\ListQcTemplates;
use App\Filament\Resources\QcTemplates\Schemas\QcTemplatesForm;
use App\Filament\Resources\QcTemplates\Tables\QcTemplatesTable;
use App\Models\Production\QcTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class QcTemplatesResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = QcTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.configuration');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.qc_templates');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('navigation.items.production_models');
    }

    public static function form(Schema $schema): Schema
    {
        return QcTemplatesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QcTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQcTemplates::route('/'),
            'create' => CreateQcTemplates::route('/create'),
            'edit' => EditQcTemplates::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return [
            'reference-data/qc-and-task-templates',
            'execution/tasks-qc-and-outputs',
        ];
    }
}
