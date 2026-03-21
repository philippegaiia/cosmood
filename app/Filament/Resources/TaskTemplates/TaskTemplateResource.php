<?php

namespace App\Filament\Resources\TaskTemplates;

use App\Filament\Resources\TaskTemplates\Pages\CreateTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\ListTaskTemplates;
use App\Filament\Resources\TaskTemplates\Schemas\TaskTemplateForm;
use App\Filament\Resources\TaskTemplates\Tables\TaskTemplatesTable;
use App\Models\Production\TaskTemplate;
use BackedEnum;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class TaskTemplateResource extends Resource implements CopilotResource, HasKnowledgeBase
{
    protected static ?string $model = TaskTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.configuration');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.production_models');
    }

    public static function form(Schema $schema): Schema
    {
        return TaskTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaskTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaskTemplates::route('/'),
            'create' => CreateTaskTemplate::route('/create'),
            'edit' => EditTaskTemplate::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return [
            'reference-data/qc-and-task-templates',
            'execution/tasks-qc-and-outputs',
        ];
    }

    public static function copilotResourceDescription(): ?string
    {
        return __('Task templates define the sequence of production tasks for different product types');
    }

    public static function copilotTools(): array
    {
        return [
            CopilotTools\ListTaskTemplatesTool::class,
            CopilotTools\SearchTaskTemplatesTool::class,
            CopilotTools\ViewTaskTemplateTool::class,
        ];
    }
}
