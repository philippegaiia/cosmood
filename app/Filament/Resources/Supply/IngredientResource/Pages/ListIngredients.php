<?php

namespace App\Filament\Resources\Supply\IngredientResource\Pages;

use App\Filament\Resources\Supply\IngredientResource;
use App\Filament\Resources\Supply\IngredientResource\Tables\IngredientStockTable;
use App\Models\Supply\Ingredient;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListIngredients extends ListRecords
{
    protected static string $resource = IngredientResource::class;

    public function getTabs(): array
    {
        return [
            'reference' => Tab::make('Vue référence')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query),

            'stock' => Tab::make('Stock')
                ->badge(function (): int {
                    return Ingredient::query()
                        ->where('stock_min', '>', 0)
                        ->get()
                        ->filter(fn ($i) => $i->getTotalAvailableStock() <= $i->stock_min)
                        ->count();
                })
                ->badgeColor('warning'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'reference';
    }

    /**
     * Get the table instance based on active tab.
     */
    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        $activeTab = $this->getActiveTab();

        if ($activeTab === 'stock') {
            return IngredientStockTable::configure($table);
        }

        // Return default IngredientResource table
        return IngredientResource::table($table);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
