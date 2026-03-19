<?php

namespace App\Filament\Resources\Production;

use App\Filament\Resources\Production\ProductionResource\CopilotTools\ListProductionsTool;
use App\Filament\Resources\Production\ProductionResource\CopilotTools\SearchProductionsTool;
use App\Filament\Resources\Production\ProductionResource\CopilotTools\ViewProductionTool;
use App\Filament\Resources\Production\ProductionResource\Pages\CreateProduction;
use App\Filament\Resources\Production\ProductionResource\Pages\EditProduction;
use App\Filament\Resources\Production\ProductionResource\Pages\ListProductions;
use App\Filament\Resources\Production\ProductionResource\Pages\ViewProduction;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionItemsRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionOutputsRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionQcChecksRelationManager;
use App\Filament\Resources\Production\ProductionResource\RelationManagers\ProductionTasksRelationManager;
use App\Filament\Resources\Production\ProductionResource\Schemas\ProductionForm;
use App\Filament\Resources\Production\ProductionResource\Tables\ProductionsTable;
use App\Models\Production\Production;
use App\Services\Production\StatusColorScheme;
use BackedEnum;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

/**
 * Production resource definition.
 *
 * This resource manages productions (batches) in the cosmetics manufacturing system.
 * It delegates form configuration to ProductionForm and table configuration to
 * ProductionsTable, following Filament v5 best practices.
 *
 * Production items are managed via the ProductionItemsRelationManager for better
 * reactivity and separation of concerns.
 *
 * @see ProductionForm Form schema configuration
 * @see ProductionsTable Table configuration
 * @see ProductionItemsRelationManager Items management
 */
class ProductionResource extends Resource implements CopilotResource, HasKnowledgeBase
{
    protected static ?string $model = Production::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.productions');
    }

    /**
     * Configure the production form schema.
     *
     * Delegates to ProductionForm for all form configuration.
     *
     * @param  Schema  $schema  The schema instance to configure
     * @return Schema The configured schema
     */
    public static function form(Schema $schema): Schema
    {
        return ProductionForm::configure($schema);
    }

    /**
     * Configure the productions table.
     *
     * Delegates to ProductionsTable for all table configuration.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function table(Table $table): Table
    {
        return ProductionsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Exécution'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('Statut'))
                            ->state(fn (Production $record): string => StatusColorScheme::forProduction($record)['label'])
                            ->badge()
                            ->color(fn (Production $record): string => StatusColorScheme::forProduction($record)['color']),
                        TextEntry::make('production_date')
                            ->label(__('Date production'))
                            ->date('d/m/Y')
                            ->placeholder(__('Non définie')),
                        TextEntry::make('ready_date')
                            ->label(__('Date prête'))
                            ->date('d/m/Y')
                            ->placeholder(__('Non définie')),
                        TextEntry::make('productionLine.name')
                            ->label(__('Ligne'))
                            ->placeholder(__('Sans ligne')),
                        TextEntry::make('supply_coverage')
                            ->label(__('Approvisionnement'))
                            ->state(fn (Production $record): string => $record->getSupplyCoverageLabel())
                            ->badge()
                            ->color(fn (Production $record): string => $record->getSupplyCoverageColor()),
                        TextEntry::make('wave.name')
                            ->label(__('Vague'))
                            ->placeholder(__('Aucune')),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * Get the relation managers for this resource.
     *
     * Returns the managers for production items, QC checks, and tasks.
     *
     * @return array<int, class-string> The relation manager classes
     */
    public static function getRelations(): array
    {
        return [
            ProductionItemsRelationManager::class,
            ProductionOutputsRelationManager::class,
            ProductionTasksRelationManager::class,
            ProductionQcChecksRelationManager::class,
        ];
    }

    /**
     * Get the pages for this resource.
     *
     * @return array<string, class-string> The page classes keyed by route name
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProductions::route('/'),
            'create' => CreateProduction::route('/create'),
            'view' => ViewProduction::route('/{record}'),
            'edit' => EditProduction::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return [
            'execution/productions',
            'execution/tasks-qc-and-outputs',
            'stock-and-allocations/allocations',
        ];
    }

    public static function copilotResourceDescription(): ?string
    {
        return 'Read-only access to production batches, their statuses, dates, lines, waves, and supply-readiness context.';
    }

    public static function copilotTools(): array
    {
        return [
            new ListProductionsTool,
            new SearchProductionsTool,
            new ViewProductionTool,
        ];
    }
}
