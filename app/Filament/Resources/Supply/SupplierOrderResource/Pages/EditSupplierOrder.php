<?php

namespace App\Filament\Resources\Supply\SupplierOrderResource\Pages;

use App\Filament\Resources\Supply\SupplierOrderResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSupplierOrder extends EditRecord
{
    protected static string $resource = SupplierOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('Exporter PO PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn (): string => route('supplier-orders.po-pdf', $this->record))
                ->openUrlInNewTab(),
            Action::make('printPo')
                ->label('Imprimer PO')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('supplier-orders.po-print', $this->record))
                ->openUrlInNewTab(),
            Action::make('copyEmail')
                ->label('Copier email')
                ->icon('heroicon-o-clipboard-document')
                ->url(fn (): string => route('supplier-orders.po-email-copy', $this->record))
                ->openUrlInNewTab(),
            DeleteAction::make()->action(function ($data, $record) {
                if ($record->supplier_order_items()->count() > 0) {
                    Notification::make()
                        ->danger()
                        ->title('Opération Impossible')
                        ->body('Cette commande contient des ingrédients commandés. Effacez les pour la supprimer.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Commande Supprimée')
                    ->body('La Commande '.$record->order_ref.'a été supprimée avec succès.')
                    ->send();

                $record->delete();
            }),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
