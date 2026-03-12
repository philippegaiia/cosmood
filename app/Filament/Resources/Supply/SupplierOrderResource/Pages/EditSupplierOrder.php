<?php

namespace App\Filament\Resources\Supply\SupplierOrderResource\Pages;

use App\Filament\Resources\Supply\SupplierOrderResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSupplierOrder extends EditRecord
{
    protected static string $resource = SupplierOrderResource::class;

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
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
                ->label('Exporter PO PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => route('supplier-orders.po-pdf', $this->record))
                ->openUrlInNewTab(),
            Action::make('printPo')
                ->label('Imprimer PO')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('supplier-orders.po-print', $this->record))
                ->openUrlInNewTab(),
            Action::make('copyEmail')
                ->label('Copier email')
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
}
