<?php

namespace App\Filament\Resources\Production\ProductionResource\RelationManagers;

use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Models\Production\ProductionItem;
use App\Services\Production\WaveRequirementStatusService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ProductionItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'productionItems';

    protected static ?string $title = 'Items de production';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('ingredient.name')
                    ->label('Ingrédient')
                    ->searchable(),
                TextColumn::make('phase')
                    ->label('Phase')
                    ->badge()
                    ->formatStateUsing(fn (ProductionItem $record): string => $record->getPhaseLabel())
                    ->color(fn (ProductionItem $record): string|array|null => Phases::tryFrom((string) $record->phase)?->getColor())
                    ->sortable(query: function (EloquentBuilder $query, string $direction): EloquentBuilder {
                        return $query->orderByRaw('CAST(phase AS INTEGER) '.$direction);
                    }),
                TextColumn::make('percentage_of_oils')
                    ->label('Coefficient')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('calculated_quantity')
                    ->label('Quantité calculée')
                    ->state(fn (ProductionItem $record): float => $record->getCalculatedQuantityKg())
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('product_cost')
                    ->label('Coût produit')
                    ->state(fn (ProductionItem $record): ?float => $record->getEstimatedCost())
                    ->money('EUR')
                    ->summarize(
                        Summarizer::make('total_cost')
                            ->label('Total')
                            ->using(function (QueryBuilder $query): float {
                                $total = $query
                                    ->leftJoin('supplies', 'production_items.supply_id', '=', 'supplies.id')
                                    ->leftJoin('supplier_listings', 'production_items.supplier_listing_id', '=', 'supplier_listings.id')
                                    ->leftJoin('ingredients', 'production_items.ingredient_id', '=', 'ingredients.id')
                                    ->leftJoin('productions', 'production_items.production_id', '=', 'productions.id')
                                    ->selectRaw("SUM((CASE WHEN production_items.phase = '40' THEN (COALESCE(productions.expected_units, 0) * COALESCE(production_items.percentage_of_oils, 0)) ELSE ((COALESCE(productions.planned_quantity, 0) * COALESCE(production_items.percentage_of_oils, 0)) / 100) END) * COALESCE(supplies.unit_price, supplier_listings.price, ingredients.price, 0)) as total_cost")
                                    ->value('total_cost');

                                return round((float) ($total ?? 0), 2);
                            })
                    )
                    ->placeholder('-'),
                IconColumn::make('organic')
                    ->label('Bio')
                    ->boolean(),
                IconColumn::make('is_supplied')
                    ->label('Approvisionné')
                    ->boolean()
                    ->state(fn (ProductionItem $record): bool => $record->is_supplied || $record->supply_id !== null || $record->allocations->contains(fn ($allocation): bool => $allocation->isActive())),
                TextColumn::make('is_order_marked')
                    ->label(__('Commande passée'))
                    ->state(fn (ProductionItem $record): string => $record->is_order_marked ? __('Oui') : __('Non'))
                    ->badge()
                    ->color(fn (ProductionItem $record): string => $record->is_order_marked ? 'info' : 'gray'),
                TextColumn::make('supply_batch_number')
                    ->label('Lot supply')
                    ->state(fn (ProductionItem $record): ?string => $record->allocations
                        ->first(fn ($allocation): bool => $allocation->isActive())?->supply?->batch_number
                        ?? $record->supply_batch_number)
                    ->placeholder('Non sélectionné'),
                TextColumn::make('supplierListing.name')
                    ->label('Listing fournisseur')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('toggleOrderMark')
                    ->label(fn (ProductionItem $record): string => $record->is_order_marked
                        ? __('Retirer marque commande')
                        : __('Marquer commande'))
                    ->icon(fn (ProductionItem $record): Heroicon => $record->is_order_marked
                        ? Heroicon::OutlinedMinusCircle
                        : Heroicon::OutlinedCheckCircle)
                    ->color(fn (ProductionItem $record): string => $record->is_order_marked ? 'warning' : 'info')
                    ->requiresConfirmation()
                    ->action(function (ProductionItem $record): void {
                        $record->loadMissing('production.wave');

                        $nextMarkState = ! $record->is_order_marked;

                        $record->update([
                            'is_order_marked' => $nextMarkState,
                            'procurement_status' => $record->isFullyAllocated()
                                ? ProcurementStatus::Received
                                : ($nextMarkState ? ProcurementStatus::Ordered : ProcurementStatus::NotOrdered),
                        ]);

                        if ($record->production?->wave) {
                            app(WaveRequirementStatusService::class)->syncForWave($record->production->wave);
                        }

                        Notification::make()
                            ->title($nextMarkState ? __('Commande marquée') : __('Marquage retiré'))
                            ->success()
                            ->send();
                    }),
            ])
            ->modifyQueryUsing(fn (EloquentBuilder $query): EloquentBuilder => $query->with(['ingredient', 'supplierListing', 'supply', 'production', 'allocations.supply']))
            ->defaultSort('sort');
    }
}
