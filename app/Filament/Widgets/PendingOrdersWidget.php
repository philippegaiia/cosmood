<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Supply\SupplierOrderResource;
use App\Models\Supply\SupplierOrder;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;

/**
 * Pending Orders Widget.
 *
 * Shows supplier orders awaiting action:
 * - Status = Passed, Confirmed, Delivered (not yet Checked)
 * - Shows expected delivery date
 * - Highlights overdue orders
 *
 * Quick action to view order details.
 */
class PendingOrdersWidget extends BaseWidget
{
    protected static ?string $heading = 'Commandes en attente';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 6,
        'lg' => 6,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SupplierOrder::query()
                    ->with('supplier')
                    ->whereIn('order_status', [
                        OrderStatus::Passed,
                        OrderStatus::Confirmed,
                        OrderStatus::Delivered,
                    ])
                    ->orderByRaw("
                        CASE 
                            WHEN order_status = '4' THEN 1  -- Delivered (urgent)
                            WHEN order_status = '3' THEN 2  -- Confirmed
                            WHEN order_status = '2' THEN 3  -- Passed
                            ELSE 4
                        END
                    ")
                    ->orderBy('delivery_date')
            )
            ->columns([
                TextColumn::make('order_ref')
                    ->label('Référence')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('order_status')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),

                TextColumn::make('delivery_date')
                    ->label('Livraison')
                    ->date('d/m/Y')
                    ->color(function (SupplierOrder $record): ?string {
                        if (! $record->delivery_date) {
                            return null;
                        }

                        $deliveryDate = $record->delivery_date instanceof Carbon
                            ? $record->delivery_date
                            : Carbon::parse($record->delivery_date);

                        if ($deliveryDate->isPast()) {
                            return 'danger';
                        }

                        if ($deliveryDate->diffInDays(now()) <= 3) {
                            return 'warning';
                        }

                        return 'success';
                    })
                    ->sortable(),

                TextColumn::make('order_date')
                    ->label('Commande')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->actions([
                Action::make('view')
                    ->label('Voir')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->url(fn (SupplierOrder $record): string => SupplierOrderResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Aucune commande en attente')
            ->emptyStateDescription('Toutes les commandes ont été traitées.')
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return true;
    }
}
