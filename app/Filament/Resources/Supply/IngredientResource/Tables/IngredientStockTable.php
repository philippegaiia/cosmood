<?php

namespace App\Filament\Resources\Supply\IngredientResource\Tables;

use App\Enums\IngredientStockStatus;
use App\Filament\Resources\Supply\SupplyResource;
use App\Models\Supply\Ingredient;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Section;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ingredient Stock table configuration.
 *
 * Shows consolidated stock view per ingredient with:
 * - Aggregated quantities across all supply lots
 * - Visual status indicators
 * - Expandable section showing individual lots
 * - Quick actions to view supplies and create orders
 */
class IngredientStockTable
{
    /**
     * Configure the ingredient stock table.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['ingredient_category', 'supplier_listings.supplier'])
                ->withCount('supplier_listings')
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('Ingrédient'))
                    ->searchable()
                    ->sortable()
                    ->icon(fn (Ingredient $record): ?Heroicon => self::getAlertIcon($record)),

                TextColumn::make('ingredient_category.name')
                    ->label(__('Catégorie'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ViewColumn::make('stock_status')
                    ->label(__('État'))
                    ->view('components.ingredient-stock-status')
                    ->getStateUsing(function (Ingredient $record): array {
                        $available = $record->getTotalAvailableStock();
                        $total = $record->getTotalPhysicalStock();
                        $allocated = $record->getTotalAllocatedStock();
                        $minStock = $record->stock_min;
                        $status = IngredientStockStatus::fromStock($available, $minStock);

                        return [
                            'available' => $available,
                            'total' => $total,
                            'allocated' => $allocated,
                            'status' => $status,
                            'unit' => $record->base_unit?->value ?? 'kg',
                            'percentage' => $total > 0 ? ($available / $total) * 100 : 0,
                        ];
                    }),

                TextColumn::make('total_stock')
                    ->label(__('Stock total'))
                    ->state(fn (Ingredient $record): float => $record->getTotalPhysicalStock())
                    ->numeric(decimalPlaces: 3)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('(SELECT COALESCE(SUM(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0)), 0) 
                                     FROM supplies s 
                                     JOIN supplier_listings sl ON s.supplier_listing_id = sl.id 
                                     WHERE sl.ingredient_id = ingredients.id) '.$direction)),

                TextColumn::make('available_stock')
                    ->label(__('Disponible'))
                    ->state(fn (Ingredient $record): float => $record->getTotalAvailableStock())
                    ->numeric(decimalPlaces: 3)
                    ->color(fn (Ingredient $record): string => match (true) {
                        $record->getTotalAvailableStock() <= 0 => 'danger',
                        $record->stock_min > 0 && $record->getTotalAvailableStock() <= $record->stock_min => 'warning',
                        default => 'success',
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('(SELECT COALESCE(SUM(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0)), 0) 
                                     FROM supplies s 
                                     JOIN supplier_listings sl ON s.supplier_listing_id = sl.id 
                                     WHERE sl.ingredient_id = ingredients.id) '.$direction)),

                TextColumn::make('allocated_stock')
                    ->label(__('Alloué'))
                    ->state(fn (Ingredient $record): float => $record->getTotalAllocatedStock())
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('(SELECT COALESCE(SUM(allocated_quantity), 0) 
                                     FROM supplies s 
                                     JOIN supplier_listings sl ON s.supplier_listing_id = sl.id 
                                     WHERE sl.ingredient_id = ingredients.id) '.$direction)),

                TextColumn::make('supplier_listings_count')
                    ->label(__('Lots'))
                    ->counts('supplier_listings')
                    ->sortable(),

                TextColumn::make('stock_min')
                    ->label(__('Stock min'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('base_unit')
                    ->label(__('Unité'))
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('ingredient_category')
                    ->label(__('Catégorie'))
                    ->relationship('ingredient_category', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('has_stock')
                    ->label(__('A du stock'))
                    ->placeholder(__('Tous'))
                    ->trueLabel('En stock')
                    ->falseLabel('Rupture')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('supplier_listings.supplies', fn ($q) => $q
                            ->where('is_in_stock', true)
                            ->whereRaw('COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0) > 0')),
                        false: fn (Builder $query) => $query->whereDoesntHave('supplier_listings.supplies', fn ($q) => $q
                            ->where('is_in_stock', true)
                            ->whereRaw('COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0) - COALESCE(allocated_quantity, 0) > 0')),
                    ),

                SelectFilter::make('has_out_of_stock_lots')
                    ->label(__('Lots épuisés'))
                    ->options([
                        'yes' => 'Avec lots épuisés',
                        'no' => 'Tous les lots actifs',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] === 'yes',
                            fn (Builder $query) => $query->whereHas('supplier_listings.supplies',
                                fn ($q) => $q->where('is_in_stock', false))
                        )->when(
                            $data['value'] === 'no',
                            fn (Builder $query) => $query->whereDoesntHave('supplier_listings.supplies',
                                fn ($q) => $q->where('is_in_stock', false))
                        );
                    }),

                TernaryFilter::make('stock_alert')
                    ->label(__('Alerte stock'))
                    ->placeholder(__('Tous'))
                    ->trueLabel('Stock faible')
                    ->falseLabel('Stock OK')
                    ->queries(
                        true: fn (Builder $query) => $query
                            ->where('stock_min', '>', 0)
                            ->whereRaw('(SELECT COALESCE(SUM(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0)), 0) 
                                       FROM supplies s 
                                       JOIN supplier_listings sl ON s.supplier_listing_id = sl.id 
                                       WHERE sl.ingredient_id = ingredients.id 
                                       AND s.is_in_stock = 1) <= stock_min'),
                        false: fn (Builder $query) => $query
                            ->where(function ($q) {
                                $q->where('stock_min', '<=', 0)
                                    ->orWhereRaw('(SELECT COALESCE(SUM(COALESCE(quantity_in, initial_quantity, 0) - COALESCE(quantity_out, 0)), 0) 
                                                FROM supplies s 
                                                JOIN supplier_listings sl ON s.supplier_listing_id = sl.id 
                                                WHERE sl.ingredient_id = ingredients.id 
                                                AND s.is_in_stock = 1) > stock_min');
                            }),
                    ),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('view_supplies')
                        ->label(__('Voir lots'))
                        ->icon(Heroicon::Eye)
                        ->color('gray')
                        ->url(fn (Ingredient $record): string => SupplyResource::getUrl('index', [
                            'filters' => [
                                'ingredient' => [
                                    'value' => $record->id,
                                ],
                            ],
                        ])),

                    Action::make('create_order')
                        ->label(__('Commander'))
                        ->icon(Heroicon::ShoppingCart)
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading(__('Créer une commande'))
                        ->modalDescription(__('Voulez-vous créer une commande fournisseur pour cet ingrédient ?'))
                        ->action(function (Ingredient $record): void {
                            // Redirect to supplier order creation with ingredient pre-filled
                            Notification::make()
                                ->success()
                                ->title(__('Redirection'))
                                ->body(__('Fonctionnalité à implémenter : créer commande pour :name', ['name' => $record->name]))
                                ->send();
                        }),

                    ViewAction::make(),

                    EditAction::make(),

                    DeleteAction::make()
                        ->action(function ($data, Ingredient $record): void {
                            if ($record->supplier_listings()->count() > 0) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('Opération Impossible'))
                                    ->body(__('Supprimez les références fournisseur liées à l\'ingrédient :name pour le supprimer.', ['name' => $record->name]))
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Ingrédient Supprimé'))
                                ->body(__('L\'ingrédient :name a été supprimé avec succès.', ['name' => $record->name]))
                                ->send();

                            $record->delete();
                        }),
                ]),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('name', 'asc');
    }

    /**
     * Get alert icon if ingredient's stock is below minimum.
     */
    private static function getAlertIcon(Ingredient $record): ?Heroicon
    {
        $available = $record->getTotalAvailableStock();
        $minStock = $record->stock_min;

        if ($minStock > 0 && $available <= $minStock) {
            return Heroicon::ExclamationTriangle;
        }

        return null;
    }
}
