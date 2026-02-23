<?php

namespace App\Filament\Resources\Supply;

use App\Filament\Resources\Supply\IngredientResource\Pages\CreateIngredient;
use App\Filament\Resources\Supply\IngredientResource\Pages\EditIngredient;
use App\Filament\Resources\Supply\IngredientResource\Pages\ListIngredients;
use App\Models\Supply\Ingredient;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IngredientResource extends Resource
{
    protected static ?string $model = Ingredient::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Ingrédients';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-m-square-3-stack-3d';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ingredient_category_id')
                    ->relationship('ingredient_category', 'name')
                    ->native(false)
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->required()
                    ->maxLength(255),
                TextInput::make('price')
                    ->label('Dernier prix (EUR/kg)')
                    ->numeric()
                    ->step(0.01),
                TextInput::make('slug')
                    ->maxLength(255),
                TextInput::make('name_en')
                    ->maxLength(255),
                TextInput::make('inci')
                    ->maxLength(255),
                TextInput::make('inci_naoh')
                    ->maxLength(255),
                TextInput::make('inci_koh')
                    ->maxLength(255),
                TextInput::make('cas')
                    ->maxLength(255),
                TextInput::make('cas_einecs')
                    ->maxLength(255),
                TextInput::make('einecs')
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ingredient_category.name')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Dernier prix')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('name_en')
                    ->searchable(),
                TextColumn::make('inci')
                    ->searchable(),
                TextColumn::make('inci_naoh')
                    ->searchable(),
                TextColumn::make('inci_koh')
                    ->searchable(),
                TextColumn::make('cas')
                    ->searchable(),
                TextColumn::make('cas_einecs')
                    ->searchable(),
                TextColumn::make('einecs')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
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
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
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

                ]),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => ListIngredients::route('/'),
            'create' => CreateIngredient::route('/create'),
            'edit' => EditIngredient::route('/{record}/edit'),
        ];
    }
}
