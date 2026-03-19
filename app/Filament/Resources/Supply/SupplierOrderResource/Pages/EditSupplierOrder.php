<?php

namespace App\Filament\Resources\Supply\SupplierOrderResource\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Supply\SupplierOrderResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class EditSupplierOrder extends EditRecord
{
    protected static string $resource = SupplierOrderResource::class;

    public function getTitle(): string
    {
        $reference = trim((string) ($this->record->order_ref ?? 'PO-'.$this->record->id));
        $statusLabel = $this->record->order_status?->getLabel() ?? __('Brouillon');

        return __('Commande fournisseur :reference - :status', [
            'reference' => $reference,
            'status' => $statusLabel,
        ]);
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        if ($notificationData = $this->getStatusTransitionAuthorizationNotificationData()) {
            Notification::make()
                ->warning()
                ->title($notificationData['title'])
                ->body($notificationData['body'])
                ->send();

            return;
        }

        if ($notificationData = $this->getReceiptReadinessNotificationData()) {
            Notification::make()
                ->warning()
                ->title($notificationData['title'])
                ->body($notificationData['body'])
                ->send();

            return;
        }

        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('Impossible d\'enregistrer la commande'))
                ->body($exception->getMessage())
                ->warning()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label(__('Exporter PO PDF'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('supplier-orders.po-pdf', $this->record))
                ->openUrlInNewTab(),
            Action::make('printPo')
                ->label(__('Imprimer PO'))
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('supplier-orders.po-print', $this->record))
                ->openUrlInNewTab(),
            Action::make('copyEmail')
                ->label(__('Copier email'))
                ->icon(Heroicon::OutlinedClipboardDocument)
                ->url(fn (): string => route('supplier-orders.po-email-copy', $this->record))
                ->openUrlInNewTab(),
            DeleteAction::make()->action(function ($data, $record) {
                try {
                    $record->delete();

                    Notification::make()
                        ->success()
                        ->title(__('Commande supprimée'))
                        ->body(__('La commande :reference a été supprimée avec succès.', [
                            'reference' => $record->order_ref,
                        ]))
                        ->send();
                } catch (\InvalidArgumentException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('Opération impossible'))
                        ->body($exception->getMessage())
                        ->send();
                }
            }),
        ];
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function getStatusTransitionAuthorizationNotificationData(): ?array
    {
        $currentStatus = $this->record->order_status instanceof OrderStatus
            ? $this->record->order_status
            : OrderStatus::tryFrom((string) ($this->record->order_status ?? ''));

        $nextStatusRaw = $this->data['order_status'] ?? null;
        $nextStatus = $nextStatusRaw instanceof OrderStatus
            ? $nextStatusRaw
            : OrderStatus::tryFrom((string) $nextStatusRaw);

        /** @var User|null $user */
        $user = Auth::user();

        if (! $currentStatus || ! $nextStatus || ! $user || $user->canSetSupplierOrderStatus($currentStatus, $nextStatus)) {
            return null;
        }

        return [
            'title' => __('Permission insuffisante'),
            'body' => __('Vous ne pouvez pas appliquer cette transition de statut à la commande fournisseur.'),
        ];
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function getReceiptReadinessNotificationData(): ?array
    {
        $currentStatus = $this->record->order_status instanceof OrderStatus
            ? $this->record->order_status
            : OrderStatus::tryFrom((string) ($this->record->order_status ?? ''));

        $nextStatusRaw = $this->data['order_status'] ?? null;
        $nextStatus = $nextStatusRaw instanceof OrderStatus
            ? $nextStatusRaw
            : OrderStatus::tryFrom((string) $nextStatusRaw);

        $effectiveStatus = $nextStatus ?? $currentStatus;

        if ($effectiveStatus !== OrderStatus::Checked) {
            return null;
        }

        $deliveryDate = $this->data['delivery_date'] ?? $this->record->delivery_date?->toDateString();
        $items = is_array($this->data['supplier_order_items'] ?? null)
            ? $this->data['supplier_order_items']
            : [];

        $issues = collect($items)
            ->values()
            ->map(function (mixed $itemState, int $index) use ($deliveryDate): ?string {
                if (! is_array($itemState) || SupplierOrderResource::isReceiptStateLocked($itemState)) {
                    return null;
                }

                $missingFields = SupplierOrderResource::getMissingReceiptFieldsForState(
                    orderStatus: OrderStatus::Checked,
                    deliveryDate: filled($deliveryDate) ? (string) $deliveryDate : null,
                    itemState: $itemState,
                );

                if ($missingFields === []) {
                    return null;
                }

                return __('Ligne :line: :fields', [
                    'line' => $index + 1,
                    'fields' => implode(', ', $missingFields),
                ]);
            })
            ->filter()
            ->values();

        if ($issues->isEmpty()) {
            return null;
        }

        return [
            'title' => __('Réception incomplète'),
            'body' => $issues->take(3)->implode(' | '),
        ];
    }
}
