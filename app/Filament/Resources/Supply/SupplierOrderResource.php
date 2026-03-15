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
use App\Models\User;
use App\Services\InventoryMovementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
                Section::make(__('Suivi commande'))
                    ->visibleOn('edit')
                    ->schema([
                        Placeholder::make('status_summary')
                            ->label(__('Statut'))
                            ->content(fn (?SupplierOrder $record): string => self::getOrderStatusSummary($record)),
                        Placeholder::make('stock_progress_summary')
                            ->label(__('Réception stock'))
                            ->content(fn (?SupplierOrder $record): string => self::getStockProgressSummary($record)),
                        Placeholder::make('receipt_ready_summary')
                            ->label(__('Prêt à réceptionner'))
                            ->content(fn (?SupplierOrder $record): string => self::getReceiptReadySummary($record)),
                        Placeholder::make('stock_alert_summary')
                            ->label(__('Alerte'))
                            ->content(fn (?SupplierOrder $record): string => self::getStockAlertSummary($record)),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),

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
                                $serie = (SupplierOrder::max('serial_number') ?? 0) + 1;
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
                                    ->options(fn (?SupplierOrder $record): array => self::getStatusOptions($record))
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
                            ->helperText(function (Get $get): string {
                                if (! filled($get('supplier_id'))) {
                                    return __('Sélectionner un fournisseur pour filtrer les articles disponibles.');
                                }

                                return __('Ajoutez les lignes de commande directement ici avant d’enregistrer la commande.');
                            })
                            ->schema([
                                Select::make('supplier_listing_id')
                                    ->relationship(
                                        name: 'supplierListing',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->where('supplier_id', $get('../../supplier_id')),
                                    )
                                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => self::formatSupplierListingOptionLabel($record))
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set) {
                                        $supplier_listing = SupplierListing::find($state);
                                        $set('unit_weight', $supplier_listing->unit_weight);
                                    })
                                    ->preload()
                                    ->required()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->disabled(fn (Get $get): bool => self::isSupplierOrderItemLocked($get))
                                    ->native(false)
                                    ->columnSpan(5)
                                    ->helperText(fn (Get $get): ?string => self::getSupplierListingSelectionHint($get))
                                    ->searchable(),

                                TextInput::make('quantity')
                                    ->label('Nb UOM')
                                    ->numeric()
                                    ->minValue(fn (Get $get): float|int => self::isUnitBasedSupplierListingSelection($get) ? 1 : 0.001)
                                    ->step(fn (Get $get): float|int => self::isUnitBasedSupplierListingSelection($get) ? 1 : 0.001)
                                    ->inputMode(fn (Get $get): string => self::isUnitBasedSupplierListingSelection($get) ? 'numeric' : 'decimal')
                                    ->live()
                                    ->dehydrated()
                                    ->default(1)
                                    ->disabled(fn (Get $get): bool => self::isSupplierOrderItemLocked($get))
                                    ->required()
                                    ->helperText(__('Nombre d\'UOM fournisseur commandees.'))
                                    ->rule(function (Get $get): \Closure {
                                        return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                            if (! self::isUnitBasedSupplierListingSelection($get)) {
                                                return;
                                            }

                                            if (abs((float) $value - round((float) $value)) > 0.0001) {
                                                $fail(__('La quantité doit être un nombre entier pour les ingrédients unitaires.'));
                                            }
                                        };
                                    })
                                    ->columnSpan(2),

                                TextInput::make('unit_weight')
                                    ->label(fn (Get $get): string => self::getUnitWeightFieldLabel($get))
                                    ->disabled()
                                    ->dehydrated()
                                    ->default(1)
                                    ->suffix(fn (Get $get): string => self::getSupplierListingUnitLabel($get))
                                    ->helperText(fn (Get $get): string => __('Contenu d\'une UOM fournisseur en :unit.', ['unit' => self::getSupplierListingUnitLabel($get)]))
                                    ->columnSpan(2),

                                TextInput::make('unit_price')
                                    ->label('Prix')
                                    // ->dehydrated()
                                    ->numeric()
                                    ->minValue(0)
                                    ->disabled(fn (Get $get): bool => self::isSupplierOrderItemLocked($get))
                                    ->columnSpan(2),

                                TextInput::make('batch_number')
                                    ->label('No. Lot')
                                    // ->live()
                                    ->disabled(fn (Get $get): bool => self::isSupplierOrderItemLocked($get))
                                    ->columnSpan(2),

                                DatePicker::make('expiry_date')
                                    ->label('DLUO')
                                    ->displayFormat('M Y')
                                    ->native(false)
                                    ->closeOnDateSelection()
                                    ->disabled(fn (Get $get): bool => self::isSupplierOrderItemLocked($get))
                                    ->columnSpan(2),

                                TextEntry::make('total_quantity')
                                    ->label(fn (Get $get): string => __('Total (:unit)', ['unit' => self::getSupplierListingUnitLabel($get)]))
                                    ->state(function (Get $get): string {
                                        $quantity = (float) ($get('quantity') ?? 0);
                                        $unit = self::getSupplierListingUnitLabel($get);
                                        $unitWeight = (float) ($get('unit_weight') ?? 0);
                                        $unitMultiplier = $unitWeight > 0 ? $unitWeight : 1;

                                        return self::formatQuantityForDisplay($quantity * $unitMultiplier, $unit);
                                    })->columnSpan(1),

                                TextInput::make('committed_quantity_kg')
                                    ->label(fn (Get $get): string => __('Engagé vague (:unit)', ['unit' => self::getSupplierListingUnitLabel($get)]))
                                    ->numeric()
                                    ->live()
                                    ->default(0)
                                    ->inputMode(fn (Get $get): string => self::isUnitBasedSupplierListingSelection($get) ? 'numeric' : 'decimal')
                                    ->disabled(fn (Get $get): bool => self::isSupplierOrderItemLocked($get))
                                    ->afterStateHydrated(function (Set $set, mixed $state): void {
                                        if ($state === null || $state === '') {
                                            $set('committed_quantity_kg', 0);
                                        }
                                    })
                                    ->dehydrateStateUsing(fn (mixed $state): float => round((float) ($state ?? 0), 3))
                                    ->minValue(0)
                                    ->maxValue(fn (Get $get): float => self::getMaximumCommittedQuantity($get))
                                    ->step(fn (Get $get): float|int => self::isUnitBasedSupplierListingSelection($get) ? 1 : 0.001)
                                    ->suffix(fn (Get $get): string => self::getSupplierListingUnitLabel($get))
                                    ->rule(function (Get $get): \Closure {
                                        return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                            if (self::isUnitBasedSupplierListingSelection($get) && abs((float) $value - round((float) $value)) > 0.0001) {
                                                $fail(__('La quantité engagée doit être un nombre entier pour les ingrédients unitaires.'));

                                                return;
                                            }

                                            $orderedKg = self::getMaximumCommittedQuantity($get);
                                            $committedKg = round((float) ($value ?? 0), 3);

                                            if ($committedKg > $orderedKg) {
                                                $fail(__('La quantité engagée (:committed :unit) ne peut pas dépasser la quantité commandée (:ordered :unit).', [
                                                    'committed' => number_format($committedKg, 3, ',', ' '),
                                                    'ordered' => number_format($orderedKg, 3, ',', ' '),
                                                    'unit' => self::getSupplierListingUnitLabel($get),
                                                ]));
                                            }
                                        };
                                    })
                                    ->helperText(function (Get $get): string {
                                        $waveId = $get('../../production_wave_id');

                                        if (! $waveId) {
                                            return __('Définir une référence vague pour engager une quantité planifiée.');
                                        }

                                        $orderedKg = self::getMaximumCommittedQuantity($get);
                                        $committedKg = (float) ($get('committed_quantity_kg') ?? 0);
                                        $unitLabel = self::getSupplierListingUnitLabel($get);

                                        if ($committedKg > $orderedKg) {
                                            return __('La quantité engagée ne peut pas dépasser la quantité commandée en :unit.', ['unit' => $unitLabel]);
                                        }

                                        return __('Quantité planifiée engagée pour la vague liée à cette commande en :unit.', ['unit' => $unitLabel]);
                                    })
                                    ->visible(fn (Get $get): bool => filled($get('../../production_wave_id')))
                                    ->columnSpan(2),

                                Placeholder::make('receipt_status')
                                    ->label(__('État réception'))
                                    ->content(fn (Get $get): string => self::getReceiptStatusSummary($get))
                                    ->columnSpan(5),

                                Hidden::make('is_in_supplies')
                                    ->dehydrated()
                                    ->default('Attente'),

                                Hidden::make('moved_to_stock_at')
                                    ->dehydrated(false),

                            ])->columns(18)
                            ->defaultItems(1)
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
                            ->addAction(fn (Action $action) => $action->label(__('Ajouter une ligne'))->icon('heroicon-m-plus')->color('success'))
                            ->extraItemActions([
                                Action::make('createNewInventory')
                                    ->label('Créer Stock')
                                    ->hidden(
                                        fn (array $arguments, Repeater $component, $record) => ! (Auth::user()?->canReceiveSupplierOrdersIntoStock() ?? false)
                                            || ! isset($component->getRawItemState($arguments['item'])['id'])
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
                                        if (! (Auth::user()?->canReceiveSupplierOrdersIntoStock() ?? false)) {
                                            Notification::make()
                                                ->title(__('Accès refusé'))
                                                ->body(__('Vous n’avez pas l’autorisation de réceptionner ce lot en stock.'))
                                                ->warning()
                                                ->send();

                                            return;
                                        }

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

                                        $missingFields = self::getMissingReceiptFieldsForState(
                                            orderStatus: $record->order_status,
                                            deliveryDate: $record->delivery_date?->toDateString(),
                                            itemState: [
                                                'quantity' => $quantity,
                                                'unit_price' => $unitPrice,
                                                'batch_number' => $batchNumber,
                                                'expiry_date' => $expiryDate,
                                            ],
                                        );

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'supplier_order_items',
                'supplier_order_items as pending_stock_items_count' => fn (Builder $query): Builder => $query->whereNull('moved_to_stock_at'),
            ]))
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

                TextColumn::make('stock_follow_up')
                    ->label('Suivi stock')
                    ->state(fn (SupplierOrder $record): ?string => self::getOrderListStockBadgeLabel($record))
                    ->badge()
                    ->color(fn (SupplierOrder $record): string => self::getOrderListStockBadgeColor($record))
                    ->placeholder('-'),

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
                //
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

    private static function isSupplierOrderItemLocked(Get $get): bool
    {
        return filled($get('moved_to_stock_at')) || $get('is_in_supplies') === 'Stock';
    }

    private static function isUnitBasedSupplierListingSelection(Get $get): bool
    {
        $supplierListingId = $get('supplier_listing_id');

        if (! filled($supplierListingId)) {
            return false;
        }

        $supplierListing = SupplierListing::query()
            ->with('ingredient:id,base_unit')
            ->find($supplierListingId);

        return $supplierListing?->isUnitBased() ?? false;
    }

    private static function getSupplierListingUnitLabel(Get $get): string
    {
        $supplierListingId = $get('supplier_listing_id');

        if (! filled($supplierListingId)) {
            return 'kg';
        }

        $supplierListing = SupplierListing::query()->find($supplierListingId);

        return $supplierListing?->getNormalizedUnitOfMeasure() ?? 'kg';
    }

    private static function getMaximumCommittedQuantity(Get $get): float
    {
        $quantity = (float) ($get('quantity') ?? 0);
        $unitWeight = (float) ($get('unit_weight') ?? 0);
        $unitMultiplier = $unitWeight > 0 ? $unitWeight : 1;

        return round($quantity * $unitMultiplier, 3);
    }

    private static function formatQuantityForDisplay(float $quantity, string $unit): string
    {
        if ($unit === 'u') {
            return number_format(round($quantity), 0, ',', ' ').' u';
        }

        return number_format($quantity, 3, ',', ' ').' '.$unit;
    }

    private static function formatSupplierListingOptionLabel(Model $record): string
    {
        $supplierCode = trim((string) ($record->supplier_code ?? ''));
        $label = trim((string) ($record->name ?? __('Article')));
        $normalizedUnit = $record instanceof SupplierListing
            ? $record->getNormalizedUnitOfMeasure()
            : SupplierListing::normalizeUnitOfMeasure((string) ($record->unit_of_measure ?? 'kg'));
        $weightValue = (float) ($record->unit_weight ?? 0);
        $displayWeight = $weightValue > 0 ? $weightValue : 1;
        $weightLabel = self::formatUomValue($displayWeight, $normalizedUnit);

        if ($supplierCode !== '') {
            $label = '['.$supplierCode.'] '.$label;
        }

        return trim($label.' ('.$weightLabel.')');
    }

    private static function getUnitWeightFieldLabel(Get $get): string
    {
        $unit = self::getSupplierListingUnitLabel($get);

        return $unit === 'u'
            ? __('UOM (unités)')
            : __('UOM (:unit)', ['unit' => $unit]);
    }

    private static function formatUomValue(float $value, string $unit): string
    {
        $decimals = $unit === 'u' || abs($value - round($value)) <= 0.0001 ? 0 : 3;

        return trim(number_format($value, $decimals, ',', ' ').' '.$unit);
    }

    private static function getSupplierListingSelectionHint(Get $get): ?string
    {
        $supplierListingId = $get('supplier_listing_id');

        if (! filled($supplierListingId)) {
            return null;
        }

        $supplierCode = SupplierListing::query()
            ->whereKey($supplierListingId)
            ->value('supplier_code');

        $unitOfMeasure = SupplierListing::query()
            ->whereKey($supplierListingId)
            ->value('unit_of_measure');

        if (! filled($supplierCode) && ! filled($unitOfMeasure)) {
            return null;
        }

        $parts = [];

        if (filled($supplierCode)) {
            $parts[] = __('Code fournisseur: :code', ['code' => $supplierCode]);
        }

        if (filled($unitOfMeasure)) {
            $parts[] = __('UOM: :unit', ['unit' => SupplierListing::normalizeUnitOfMeasure((string) $unitOfMeasure)]);
        }

        return implode(' - ', $parts);
    }

    /**
     * @return array<int, string>
     */
    public static function getMissingReceiptFieldsForState(OrderStatus|string|null $orderStatus, ?string $deliveryDate, array $itemState): array
    {
        $missingFields = [];
        $normalizedStatus = $orderStatus instanceof OrderStatus
            ? $orderStatus
            : OrderStatus::tryFrom((string) $orderStatus);

        if ($normalizedStatus !== OrderStatus::Checked) {
            $missingFields[] = __('statut Contrôlée');
        }

        if (blank($deliveryDate)) {
            $missingFields[] = __('date de livraison');
        }

        if ((float) ($itemState['quantity'] ?? 0) <= 0) {
            $missingFields[] = __('quantité');
        }

        if (blank($itemState['unit_price'] ?? null)) {
            $missingFields[] = __('prix');
        }

        if (blank($itemState['batch_number'] ?? null)) {
            $missingFields[] = __('lot');
        }

        if (blank($itemState['expiry_date'] ?? null)) {
            $missingFields[] = __('DLUO');
        }

        return $missingFields;
    }

    public static function isReceiptStateLocked(array $itemState): bool
    {
        return filled($itemState['moved_to_stock_at'] ?? null)
            || (($itemState['is_in_supplies'] ?? null) === 'Stock');
    }

    private static function getReceiptStatusSummary(Get $get): string
    {
        if (self::isSupplierOrderItemLocked($get)) {
            return __('En stock');
        }

        $missingFields = self::getMissingReceiptFieldsForState(
            orderStatus: $get('../../order_status'),
            deliveryDate: $get('../../delivery_date'),
            itemState: [
                'quantity' => $get('quantity'),
                'unit_price' => $get('unit_price'),
                'batch_number' => $get('batch_number'),
                'expiry_date' => $get('expiry_date'),
            ],
        );

        if ($missingFields === []) {
            return __('Prêt à réceptionner');
        }

        return __('Manque :fields', ['fields' => implode(', ', $missingFields)]);
    }

    private static function getOrderStatusSummary(?SupplierOrder $record): string
    {
        return $record?->order_status?->getLabel() ?? __('Brouillon');
    }

    private static function getStockProgressSummary(?SupplierOrder $record): string
    {
        if (! $record) {
            return __('Aucune ligne');
        }

        $totalLines = $record->supplier_order_items()->count();

        if ($totalLines === 0) {
            return __('Aucune ligne');
        }

        $stockedLines = $record->supplier_order_items()
            ->whereNotNull('moved_to_stock_at')
            ->count();

        return __(':stocked / :total lignes en stock', [
            'stocked' => $stockedLines,
            'total' => $totalLines,
        ]);
    }

    private static function getReceiptReadySummary(?SupplierOrder $record): string
    {
        if (! $record) {
            return __('0 ligne');
        }

        $items = $record->supplier_order_items()
            ->get(['quantity', 'unit_price', 'batch_number', 'expiry_date', 'moved_to_stock_at']);

        $pendingItems = $items->filter(fn (SupplierOrderItem $item): bool => $item->moved_to_stock_at === null);

        if ($pendingItems->isEmpty()) {
            return __('Toutes les lignes sont en stock');
        }

        $readyCount = $pendingItems->filter(function (SupplierOrderItem $item) use ($record): bool {
            return self::getMissingReceiptFieldsForState(
                orderStatus: $record->order_status,
                deliveryDate: $record->delivery_date?->toDateString(),
                itemState: $item->only(['quantity', 'unit_price', 'batch_number', 'expiry_date']),
            ) === [];
        })->count();

        return __(':ready / :pending lignes prêtes', [
            'ready' => $readyCount,
            'pending' => $pendingItems->count(),
        ]);
    }

    private static function getStockAlertSummary(?SupplierOrder $record): string
    {
        if (! $record) {
            return __('Aucune alerte');
        }

        $pendingCount = $record->supplier_order_items()
            ->whereNull('moved_to_stock_at')
            ->count();

        if ($pendingCount === 0) {
            return __('Aucune ligne sans stock');
        }

        if ($record->order_status === OrderStatus::Checked) {
            return $pendingCount === 1
                ? __('1 ligne contrôlée sans entrée de stock')
                : __(':count lignes contrôlées sans entrée de stock', ['count' => $pendingCount]);
        }

        return $pendingCount === 1
            ? __('1 ligne en attente de stock')
            : __(':count lignes en attente de stock', ['count' => $pendingCount]);
    }

    private static function getOrderListStockBadgeLabel(SupplierOrder $record): ?string
    {
        $pendingCount = (int) ($record->pending_stock_items_count ?? 0);

        if ($record->order_status !== OrderStatus::Checked) {
            return null;
        }

        if ($pendingCount > 0) {
            return __('Stock manquant');
        }

        return __('Stock complet');
    }

    private static function getOrderListStockBadgeColor(SupplierOrder $record): string
    {
        $pendingCount = (int) ($record->pending_stock_items_count ?? 0);

        if ($record->order_status !== OrderStatus::Checked) {
            return 'gray';
        }

        return $pendingCount > 0 ? 'warning' : 'success';
    }

    /**
     * @return array<string, string>
     */
    private static function getStatusOptions(?SupplierOrder $record): array
    {
        if (! $record?->order_status instanceof OrderStatus) {
            return [
                OrderStatus::Draft->value => OrderStatus::Draft->getLabel(),
            ];
        }

        /** @var User|null $user */
        $user = Auth::user();

        return collect(SupplierOrder::allowedTransitionsFor($record->order_status))
            ->filter(fn (OrderStatus $status): bool => $user?->canSetSupplierOrderStatus($record->order_status, $status) ?? false)
            ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->getLabel()])
            ->all();
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

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit');
    }

    // protected $listeners = ['refreshIsInSupplies' => '$refresh'];
}
