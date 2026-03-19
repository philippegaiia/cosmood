<?php

namespace App\Filament\Resources\Supply\IngredientResource\Pages;

use App\Filament\Resources\Supply\IngredientResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditIngredient extends EditRecord
{
    protected static string $resource = IngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->action(function ($data, $record) {
                if ($record->supplier_listings()->count() > 0) {
                    Notification::make()
                        ->danger()
                        ->title(__('Opération Impossible'))
                        ->body(__('Supprimez les ingrédients référencés liés à l\'ingrédient :name pour le supprimer.', ['name' => $record->name]))
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
        ];
    }
}
