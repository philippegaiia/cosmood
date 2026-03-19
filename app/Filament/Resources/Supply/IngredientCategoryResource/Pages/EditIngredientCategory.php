<?php

namespace App\Filament\Resources\Supply\IngredientCategoryResource\Pages;

use App\Filament\Resources\Supply\IngredientCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditIngredientCategory extends EditRecord
{
    protected static string $resource = IngredientCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->action(function ($data, $record) {
                if ($record->ingredients()->count() > 0) {
                    Notification::make()
                        ->danger()
                        ->title(__('Opération Impossible'))
                        ->body(__('Supprimez les ingrédients liés à la catégorie :name pour la supprimer.', ['name' => $record->name]))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Catégorie :name supprimée', ['name' => $record->name]))
                    ->body(__('La catégorie :name a été supprimée avec succès.', ['name' => $record->name]))
                    ->send();

                $record->delete();
            }),
        ];
    }
}
