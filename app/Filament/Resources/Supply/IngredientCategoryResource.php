<?php

namespace App\Filament\Resources\Supply;

use App\Filament\Resources\Supply\IngredientCategoryResource\Pages\CreateIngredientCategory;
use App\Filament\Resources\Supply\IngredientCategoryResource\Pages\EditIngredientCategory;
use App\Filament\Resources\Supply\IngredientCategoryResource\Pages\ListIngredientCategories;
use App\Models\Supply\IngredientCategory;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientCategoryResource extends Resource
{
    protected static ?string $model = IngredientCategory::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Catégories Ingrédients';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('parent_id')
                    ->relationship('parent', 'name', fn (Builder $query) => $query->where('parent_id', null))
                    ->native(false)
                    ->disabledOn('edit')
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, $record) {
                        if ($get('code') !== null) {
                            return;
                        }

                        $series = (IngredientCategory::all()->max('id') ?? 0) + 1;
                        $set('code', IngredientCategory::findOrFail($state)->code.'-'.$series);
                    }),

                TextInput::make('name')
                    ->label('Nom')
                    ->required()
                    ->maxLength(50)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', str()->slug($state))),

                TextInput::make('code')
                    ->required()
                    ->dehydrated()
                    ->unique(IngredientCategory::class, 'code')
                    ->maxLength(15),

                TextInput::make('slug')
                    ->disabledOn('edit')
                    ->dehydrated()
                    ->required()
                    ->unique()
                    ->maxLength(255),

                Toggle::make('is_visible')
                    ->required(),

                MarkdownEditor::make('description')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('name')
                    ->searchable()
                    ->label('Nom'),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('parent.name')
                    ->sortable()
                    ->label('Catégorie Parente'),
                TextColumn::make('slug')
                    ->searchable(),
                IconColumn::make('is_visible')
                    ->boolean()
                    ->label('Visible'),
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
                        if ($record->ingredients()->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Opération Impossible')
                                ->body('Supprimez les ingrédients liés à la catégorie'.$record->name.' pour la supprimer.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Catégorie Supprimée')
                            ->body('La catégorie '.$record->name.' a été supprimé avec succès.')
                            ->send();

                        $record->delete();
                    }),
                ]),
            ])
            ->toolbarActions([
                //
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
            'index' => ListIngredientCategories::route('/'),
            'create' => CreateIngredientCategory::route('/create'),
            'edit' => EditIngredientCategory::route('/{record}/edit'),
        ];
    }
}
