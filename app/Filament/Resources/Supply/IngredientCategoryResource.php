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

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.references');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.ingredient_categories');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('navigation.items.ingredients');
    }

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
                    ->label(__('Nom'))
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
                    ->label(__('Nom')),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('parent.name')
                    ->sortable()
                    ->label(__('Catégorie Parente')),
                TextColumn::make('slug')
                    ->searchable(),
                IconColumn::make('is_visible')
                    ->boolean()
                    ->label(__('Visible')),
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
                                ->title(__('Opération Impossible'))
                                ->body(__('Supprimez les ingrédients liés à la catégorie :name pour la supprimer.', ['name' => $record->name]))
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Catégorie Supprimée'))
                            ->body(__('La catégorie :name a été supprimée avec succès.', ['name' => $record->name]))
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
