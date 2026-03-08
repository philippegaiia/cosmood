<?php

namespace App\Filament\Resources\Supply;

use App\Enums\OrderStatus;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\CreateSupplierOrder;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\EditSupplierOrder;
use App\Filament\Resources\Supply\SupplierOrderResource\Pages\ListSupplierOrders;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
use App\Models\Supply\SupplierOrderItem;
use App\Services\InventoryMovementService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class SupplierOrderResource extends Resource
{
    private const FALLBACK_ESTIMATED_DELIVERY_DAYS = 8;

    protected static ?string $model = SupplierOrder::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Commandes fournisseurs';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Détails Commande')
                    ->schema([
                        Select::make('supplier_id')
                            ->relationship('supplier', 'name')
                            ->disabledOn('edit')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (blank($state)) {
                                    return;
                                }

                                $supplier = Supplier::query()->find($state);

                                if (! $supplier) {
                                    return;
                                }

                                $prefix = now()->year;
                                $supplierCode = $supplier->code;
                                $serialNumber = $get('serial_number');

                                if (filled($serialNumber)) {
                                    $set('order_ref', $prefix.'-'.$supplierCode.'-'.$serialNumber);
                                }

                                $deliveryDate = self::resolveEstimatedDeliveryDate(
                                    supplier: $supplier,
                                    orderDate: $get('order_date'),
                                );

                                if ($deliveryDate !== null) {
                                    $set('delivery_date', $deliveryDate);
                                }
                            })
                            ->native(false)
                            ->required()
                            ->columnSpan(2),

                        Select::make('production_wave_id')
                            ->label('Référence vague')
                            ->relationship('wave', 'name')
                            ->searchable()
                            ->preload()
                            ->getOptionLabelFromRecordUsing(fn (ProductionWave $record): string => $record->name.' ('.$record->slug.')')
                            ->helperText('Lie cette commande à une vague pour la mise à jour automatique des statuts d\'approvisionnement.')
                            ->columnSpan(2)
                            ->nullable(),

                        TextInput::make('serial_number')
                            ->hidden()
                            ->disabledOn('edit')
                            ->numeric()
                            ->default(function () {
                                $serie = (SupplierOrder::withTrashed()->max('serial_number') ?? 0) + 1;
                                // dd($serie);
                                $serie = str_pad($serie, 4, '0', STR_PAD_LEFT);

                                return $serie;
                            })
                            ->dehydrated()
                            ->unique(SupplierOrder::class)
                            ->required(fn (string $operation): bool => $operation === 'create'),
                        TextInput::make('order_ref')
                            ->maxLength(255)
                            ->disabled()
                            ->dehydrated(),

                        Fieldset::make('Statut commande')
                            ->schema([
                                ToggleButtons::make('order_status')
                                    ->hiddenLabel()
                                    ->options(OrderStatus::class)
                                    ->inline()
                                    ->live()
                                    ->default(OrderStatus::Draft)
                                    ->columnSpanFull(),
                            ]),

                        Fieldset::make('Dates')
                            ->schema([
                                DatePicker::make('order_date')
                                    ->label('Date Commande')
                                    ->required()
                                    ->default(now())
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                        $supplierId = $get('supplier_id');

                                        if (blank($supplierId)) {
                                            return;
                                        }

                                        $supplier = Supplier::query()->find($supplierId);

                                        if (! $supplier) {
                                            return;
                                        }

                                        $deliveryDate = self::resolveEstimatedDeliveryDate(
                                            supplier: $supplier,
                                            orderDate: $state,
                                        );

                                        if ($deliveryDate !== null) {
                                            $set('delivery_date', $deliveryDate);
                                        }
                                    })
                                    ->native(false)
                                    ->weekStartsOnMonday(),
                                DatePicker::make('delivery_date')
                                    ->helperText(__('Prérempli depuis le délai fournisseur, modifiable manuellement.'))
                                    ->afterOrEqual('order_date')
                                    ->native(false)
                                    ->weekStartsOnMonday(),
                            ])->columns(2),

                        Fieldset::make('Documents')
                            ->schema([
                                TextInput::make('confirmation_number')
                                    ->label('Numéro de confirmation')
                                    ->maxLength(50)
                                    ->columnSpan(1),
                                TextInput::make('invoice_number')
                                    ->label('Numéro facture')
                                    ->maxLength(50)
                                    ->columnSpan(1),
                                TextInput::make('bl_number')
                                    ->label('Numéro bon de livraison')
                                    ->maxLength(50)
                                    ->columnSpan(1),
                            ])->columns(3),

                        TextInput::make('freight_cost')
                            ->label('Coût de transport')
                            ->numeric(),

                        Section::make('Informations sur la Commande')
                            ->description('The items you have selected for purchase')
                            ->schema([
                                MarkdownEditor::make('description'),
                            ])
                            ->collapsed()
                            ->columns(1),

                    ])->columnSpanFull(),
                //  ]);

                Section::make('Items Commande')
                    ->schema([
                        Repeater::make('supplier_order_items')
                            ->relationship()
                            ->hiddenOn('create')
                            ->schema([
                                Select::make('supplier_listing_id')
                                    ->relationship(
                                        name: 'supplierListing',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->where('supplier_id', $get('../../supplier_id')),
                                    )
                                    ->getOptionLabelFromRecordUsing(fn (Model $record) => "{$record->name} {$record->unit_weight} {$record->unit_of_measure}")
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set) {
                                        $supplier_listing = SupplierListing::find($state);
                                        $set('unit_weight', $supplier_listing->unit_weight);
                                    })
                                    ->preload()
                                    ->required()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->native(false)
                                    ->columnSpan(5)
                                    ->searchable(),

                                TextInput::make('quantity')
                                    ->numeric()
                                    ->live()
                                    ->dehydrated()
                                    ->default(1)
                                    ->columnSpan(2),

                                TextInput::make('unit_weight')
                                    ->label('Poids')
                                    ->disabled()
                                    ->dehydrated()
                                    ->default(1)
                                    ->columnSpan(2),

                                TextInput::make('unit_price')
                                    ->label('Prix')
                                    // ->dehydrated()
                                    ->numeric()
                                    ->columnSpan(2),

                                TextInput::make('batch_number')
                                    ->label('No. Lot')
                                    // ->live()
                                    ->columnSpan(2),

                                DatePicker::make('expiry_date')
                                    ->label('DLUO')
                                    ->displayFormat('M Y')
                                    ->native(false)
                                    ->closeOnDateSelection()
                                    ->columnSpan(2),

                                TextEntry::make('total_quantity')
                                    ->label('Total')
                                    ->state(function ($get) {
                                        return $get('quantity') * $get('unit_weight');
                                    })->columnSpan(1),

                                TextInput::make('committed_quantity_kg')
                                    ->label('Engagé vague (kg)')
                                    ->numeric()
                                    ->live()
                                    ->default(0)
                                    ->afterStateHydrated(function (Set $set, mixed $state): void {
                                        if ($state === null || $state === '') {
                                            $set('committed_quantity_kg', 0);
                                        }
                                    })
                                    ->dehydrateStateUsing(fn (mixed $state): float => round((float) ($state ?? 0), 3))
                                    ->minValue(0)
                                    ->maxValue(fn (Get $get): float => round((float) ($get('quantity') ?? 0) * max(1.0, (float) ($get('unit_weight') ?? 1)), 3))
                                    ->step(0.001)
                                    ->rule(function (Get $get): \Closure {
                                        return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                            $orderedKg = round((float) ($get('quantity') ?? 0) * max(1.0, (float) ($get('unit_weight') ?? 1)), 3);
                                            $committedKg = round((float) ($value ?? 0), 3);

                                            if ($committedKg > $orderedKg) {
                                                $fail(__('La quantité engagée (:committed kg) ne peut pas dépasser la quantité commandée (:ordered kg).', [
                                                    'committed' => number_format($committedKg, 3, ',', ' '),
                                                    'ordered' => number_format($orderedKg, 3, ',', ' '),
                                                ]));
                                            }
                                        };
                                    })
                                    ->helperText(function (Get $get): string {
                                        $waveId = $get('../../production_wave_id');

                                        if (! $waveId) {
                                            return __('Définir une référence vague pour engager une quantité planifiée.');
                                        }

                                        $orderedKg = (float) ($get('quantity') ?? 0) * max(1.0, (float) ($get('unit_weight') ?? 1));
                                        $committedKg = (float) ($get('committed_quantity_kg') ?? 0);

                                        if ($committedKg > $orderedKg) {
                                            return __('La quantité engagée ne peut pas dépasser la quantité commandée.');
                                        }

                                        return __('Quantité planifiée engagée pour la vague liée à cette commande.');
                                    })
                                    ->visible(fn (Get $get): bool => filled($get('../../production_wave_id')))
                                    ->columnSpan(2),

                                Hidden::make('is_in_supplies')
                                    ->dehydrated()
                                    ->default('Attente'),

                            ])->columns(18)
                            ->defaultItems(0)
                            ->deleteAction(
                                function (Action $action) {
                                    $action->label('Supprimer')
                                        ->icon('heroicon-m-trash')
                                        ->requiresConfirmation()
                                        ->hidden(fn (array $arguments, Repeater $component) =>
                                            // !isset($component->getRawItemState($arguments['item'])['id'])
                                           // ||
                                            $component->getRawItemState($arguments['item'])['is_in_supplies'] === 'Stock'
                                        )
                                        ->color('danger');
                                }
                            )
                            ->addAction(fn (Action $action) => $action->label('Ajouter')->icon('heroicon-m-plus')->color('success'))
                            ->extraItemActions([
                                Action::make('createNewInventory')
                                    ->label('Créer Stock')
                                    ->hidden(
                                        fn (array $arguments, Repeater $component, $record) => ! isset($component->getRawItemState($arguments['item'])['id'])
                                        || ! isset($record->id)
                                        || ($record->order_status !== OrderStatus::Checked)
                                        || ! isset($record->delivery_date)
                                        || (($item = SupplierOrderItem::query()->find((int) $component->getRawItemState($arguments['item'])['id']))
                                            && ($item->isInSupplies() || $item->supply()->exists()))
                                    )
                                    ->icon('heroicon-m-arrow-trending-up')
                                    ->requiresConfirmation()

                                // ->after(function ($livewire) {
                                //      $livewire->dispatch('refreshIsInSupplies');
                                //  })
                                    ->action(function (array $arguments, Repeater $component, $record): void {
                                        $rawItemState = $component->getRawItemState($arguments['item']);
                                        $itemData = $component->getItemState($arguments['item']);

                                        $supplierOrderItemId = (int) ($rawItemState['id'] ?? 0);

                                        if (! isset($record->id) || $supplierOrderItemId <= 0) {
                                            Notification::make()
                                                ->title('Item de commande introuvable')
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        $supplierOrderItem = SupplierOrderItem::query()->find($supplierOrderItemId);

                                        if (! $supplierOrderItem) {
                                            Notification::make()
                                                ->title('Item de commande introuvable')
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        if ($supplierOrderItem->isInSupplies() || $supplierOrderItem->supply()->exists()) {
                                            Notification::make()
                                                ->title('Cet item est déjà en stock')
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        if ($record->order_status !== OrderStatus::Checked) {
                                            Notification::make()
                                                ->title('Commande non contrôlée')
                                                ->body('Le statut doit être Contrôlée pour créer le stock.')
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        $quantity = (float) ($itemData['quantity'] ?? $supplierOrderItem->quantity ?? 0);
                                        $unitPrice = $itemData['unit_price'] ?? $supplierOrderItem->unit_price;
                                        $batchNumber = $itemData['batch_number'] ?? $supplierOrderItem->batch_number;
                                        $expiryDate = $itemData['expiry_date'] ?? $supplierOrderItem->expiry_date;
                                        $deliveryDate = $record->delivery_date;

                                        $missingFields = [];

                                        if ($quantity <= 0) {
                                            $missingFields[] = 'quantité';
                                        }

                                        if (blank($unitPrice)) {
                                            $missingFields[] = 'prix';
                                        }

                                        if (blank($batchNumber)) {
                                            $missingFields[] = 'lot';
                                        }

                                        if (blank($expiryDate)) {
                                            $missingFields[] = 'DLUO';
                                        }

                                        if (blank($deliveryDate)) {
                                            $missingFields[] = 'date de livraison';
                                        }

                                        if ($missingFields !== []) {
                                            Notification::make()
                                                ->title('Données incomplètes')
                                                ->body('Compléter: '.implode(', ', $missingFields))
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        $supplierOrderItem->update([
                                            'quantity' => $quantity,
                                            'unit_price' => (float) $unitPrice,
                                            'batch_number' => (string) $batchNumber,
                                            'expiry_date' => $expiryDate,
                                        ]);

                                        try {
                                            $supply = app(InventoryMovementService::class)->receiveOrderItemIntoStock(
                                                $supplierOrderItem,
                                                (string) $record->order_ref,
                                                (string) $deliveryDate,
                                                Auth::user(),
                                            );

                                            Notification::make()
                                                ->title('Nouvelle création d\'inventaire')
                                                ->body(__('Lot final créé: :batch', ['batch' => (string) $supply->batch_number]))
                                                ->success()
                                                ->send();
                                        } catch (\RuntimeException $exception) {
                                            Notification::make()
                                                ->title('Cet item est déjà en stock')
                                                ->warning()
                                                ->send();
                                        } catch (\InvalidArgumentException $exception) {
                                            Notification::make()
                                                ->title('Impossible de créer le stock')
                                                ->body($exception->getMessage())
                                                ->warning()
                                                ->send();
                                        } catch (\Throwable $exception) {
                                            Notification::make()
                                                ->title('Erreur de création de stock')
                                                ->danger()
                                                ->send();
                                        }
                                    }),
                                // successRedirectUrl(SupplierOrderResource::getUrl())
                                // ])

                            ]),
                    ])->columnSpanFull(),
            ]);

    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Fournisseur')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('order_status')
                    ->label('Statut')
                    ->badge()
                    ->searchable(),

                TextColumn::make('order_ref')
                    ->label('Référence')
                    ->searchable(),

                TextColumn::make('wave.name')
                    ->label('Vague')
                    ->badge()
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('order_date')
                    ->label('Date Commande')
                    ->date()
                    ->sortable(),

                TextColumn::make('delivery_date')
                    ->label('Date Livraison')
                    ->date()
                    ->sortable(),

                TextColumn::make('confirmation_number')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('invoice_number')
                    ->searchable(),

                TextColumn::make('bl_number')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('freight_cost')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                SelectFilter::make('production_wave_id')
                    ->label('Vague')
                    ->relationship('wave', 'name'),
            ])

            ->recordActions([
                Action::make('exportPdf')
                    ->label('PO PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->url(fn (SupplierOrder $record): string => route('supplier-orders.po-pdf', $record))
                    ->openUrlInNewTab(),
                Action::make('printPo')
                    ->label('Imprimer PO')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (SupplierOrder $record): string => route('supplier-orders.po-print', $record))
                    ->openUrlInNewTab(),
                Action::make('copyEmail')
                    ->label('Copier email')
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->url(fn (SupplierOrder $record): string => route('supplier-orders.po-email-copy', $record))
                    ->openUrlInNewTab(),
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

    private static function resolveEstimatedDeliveryDate(Supplier $supplier, ?string $orderDate): ?string
    {
        if (blank($orderDate)) {
            return null;
        }

        $leadDays = (int) ($supplier->estimated_delivery_days ?? self::FALLBACK_ESTIMATED_DELIVERY_DAYS);

        if ($leadDays < 0) {
            $leadDays = self::FALLBACK_ESTIMATED_DELIVERY_DAYS;
        }

        return Carbon::parse($orderDate)
            ->addDays($leadDays)
            ->toDateString();
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
            'index' => ListSupplierOrders::route('/'),
            'create' => CreateSupplierOrder::route('/create'),
            'edit' => EditSupplierOrder::route('/{record}/edit'),
        ];
    }

    /**
     * Gets the Eloquent query builder for the model, without the soft deleting global scope.
     * This allows access to soft deleted models.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit');
    }

    // protected $listeners = ['refreshIsInSupplies' => '$refresh'];
}
