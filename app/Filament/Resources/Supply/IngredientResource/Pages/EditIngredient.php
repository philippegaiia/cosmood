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
                        ->title('Opération Impossible')
                        ->body('Supprimez les ingrédients référencés liés à l\'ingrédient'.$record->name.' pour le supprimer.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Ingrédient Supprimé')
                    ->body('L\'ingrédient'.$record->name.' a été supprimé avec succès.')
                    ->send();

                $record->delete();
            }),
        ];
    }
}
