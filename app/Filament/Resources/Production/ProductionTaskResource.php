<?php

namespace App\Filament\Resources\Production;

use App\Filament\Resources\Production\ProductionTaskResource\Pages\EditProductionTask;
use App\Filament\Resources\Production\ProductionTaskResource\Pages\ListProductionTasks;
use App\Filament\Resources\Production\ProductionTaskResource\Pages\ViewProductionTask;
use App\Models\Production\ProductionTask;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductionTaskResource extends Resource
{
    protected static ?string $model = ProductionTask::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?string $navigationLabel = 'Tâches';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-c-check';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contexte tâche')
                    ->schema([
                        TextInput::make('production_id')
                            ->label('Batch / Produit')
                            ->formatStateUsing(fn (mixed $state, ?ProductionTask $record): string => trim(($record?->production?->batch_number ?? '-').' - '.($record?->production?->product?->name ?? '-')))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('production_task_type_id')
                            ->label('Type tâche')
                            ->formatStateUsing(fn (mixed $state, ?ProductionTask $record): string => $record?->productionTaskType?->name ?? 'Type non renseigné')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('name')
                            ->label('Nom de tâche')
                            ->disabled()
                            ->dehydrated(false),
                        DatePicker::make('date')
                            ->label('Date')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Détails tâche de production')
                    ->schema([
                        TextEntry::make('production.batch_number')
                            ->label('Batch'),
                        TextEntry::make('production.product.name')
                            ->label('Produit')
                            ->placeholder('-'),
                        TextEntry::make('productionTaskType.name')
                            ->label('Type tâche')
                            ->placeholder('Type non renseigné'),
                        TextEntry::make('name')
                            ->label('Nom de tâche')
                            ->placeholder('-'),
                        TextEntry::make('date')
                            ->label('Date')
                            ->date('d/m/Y'),
                        TextEntry::make('is_finished')
                            ->label('Terminée')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Oui' : 'Non')
                            ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('production.batch_number')
                    ->label('Batch')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('production.product.name')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productionTaskType.name')
                    ->label('Type tâche')
                    ->placeholder('Type non renseigné')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                IconColumn::make('is_finished')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['production.product', 'productionTaskType']))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductionTasks::route('/'),
            'view' => ViewProductionTask::route('/{record}'),
            'edit' => EditProductionTask::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
