<?php

namespace App\Filament\Resources\Supply\SupplierOrderResource\RelationManagers;

use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrderItem;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplierOrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplier_order_items';

    public int $supplierId;

    /* public function getSupplierId(RelationManager $livewire): array {
                          return  $livewire->getOwnerRecord()->supplier()->pluck('supplier_id')->toArray();
                          dd($livewire->getOwnerRecord()->supplier()->pluck('supplier_id')->toArray());
                        //
                         //->toArray();
                         } */

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([

                /* Select::make('supplier_listing_id')
                        ->relationship(
                            name: 'supplier_listing',
                            titleAttribute: 'name',
                        )*/
                Select::make('supplier_listing_id')
                    ->options(function (RelationManager $livewire): array {
                        return $livewire->getOwnerRecord()->supplier_listings()
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        $supplier_listing = SupplierListing::find($state);
                        $set('unit_weight', $supplier_listing->unit_weight);
                    })
                    ->preload()
                    ->required()
                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                    ->native(false)
                    ->columnSpan(3)
                    ->searchable(),

                TextInput::make('quantity')
                    ->numeric()
                    ->minValue(0.001)
                    ->step(0.001)
                    ->live()
                    ->dehydrated()
                    ->default(1)
                    ->required()
                    ->columnSpan(1),

                TextInput::make('unit_weight')
                    ->label('Poids')
                    ->disabled()
                    ->dehydrated()
                    ->default(1)
                    ->columnSpan(1),

                TextInput::make('unit_price')
                    ->label('Prix unitaire')
                    ->numeric()
                    ->minValue(0)
                    ->live()
                    ->columnSpan(1),

                TextEntry::make('total_quantity')
                    ->label('Quantité totale')
                    ->live()
                    ->state(function (Get $get): string {
                        $quantity = (float) ($get('quantity') ?? 0);
                        $unitWeight = (float) ($get('unit_weight') ?? 0);

                        return number_format($quantity * $unitWeight, 3, '.', ' ');
                    })
                    ->columnSpan(1),

                TextEntry::make('total_price')
                    ->label('Prix total (EUR)')
                    ->live()
                    ->state(function (Get $get): string {
                        $quantity = (float) ($get('quantity') ?? 0);
                        $unitPrice = (float) ($get('unit_price') ?? 0);

                        return number_format($quantity * $unitPrice, 2, '.', ' ');
                    })
                    ->columnSpan(1),

                TextInput::make('batch_number')
                    ->label('No. Lot')
                    ->columnSpan(1),

                DatePicker::make('expiry_date')
                    ->label('DLUO')
                    ->native(false)
                    ->closeOnDateSelection()
                    ->columnSpan(1),

                Checkbox::make('is_in_supplies')
                    ->disabled()
                    ->inline(false)
                    ->columnSpan(1),

            ]);

    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('supplierListing.name'),
                TextColumn::make('unit_weight'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(function (SupplierOrderItem $record): void {
                        if ($record->isInSupplies() || $record->supply()->exists()) {
                            Notification::make()
                                ->title(__('Opération impossible'))
                                ->body(__('Cet ingrédient commandé est déjà passé en stock. Supprimez d\'abord le lot correspondant.'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title(__('Ingrédient commandé supprimé'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
