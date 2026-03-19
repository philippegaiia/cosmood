<?php

namespace App\Filament\Resources\Supply;

use App\Enums\IngredientBaseUnit;
use App\Filament\Resources\Supply\IngredientResource\Pages\CreateIngredient;
use App\Filament\Resources\Supply\IngredientResource\Pages\EditIngredient;
use App\Filament\Resources\Supply\IngredientResource\Pages\ListIngredients;
use App\Models\Supply\Ingredient;
use App\Models\Supply\IngredientCategory;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;

class IngredientResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = Ingredient::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-m-square-3-stack-3d';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.references');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.ingredients');
    }

    public static function getModelLabel(): string
    {
        return __('resources.ingredients.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('resources.ingredients.plural');
    }

    public static function getDocumentation(): array|string
    {
        return [
            'reference-data/ingredients',
            'procurement/suppliers-and-listings',
            'stock-and-allocations/stock-lots',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ingredient_category_id')
                    ->label(__('resources.ingredients.fields.category'))
                    ->relationship('ingredient_category', 'name')
                    ->native(false)
                    ->required(),
                TextInput::make('name')
                    ->label(__('resources.ingredients.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->label(__('resources.ingredients.fields.code'))
                    ->helperText(__('resources.ingredients.helpers.code'))
                    ->maxLength(255)
                    ->placeholder(fn (Get $get) => self::generateIngredientCodePreview($get('ingredient_category_id')))
                    ->live()
                    ->afterStateHydrated(function (TextInput $component, $state, $record) {
                        if ($record && empty($state)) {
                            $component->state(self::generateIngredientCode($record->ingredient_category_id));
                        }
                    }),
                Select::make('base_unit')
                    ->label(__('resources.ingredients.fields.base_unit'))
                    ->options(IngredientBaseUnit::class)
                    ->default(IngredientBaseUnit::Kg)
                    ->native(false)
                    ->required(),
                TextInput::make('price')
                    ->label(__('resources.ingredients.fields.last_price'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01),
                TextInput::make('stock_min')
                    ->label(__('resources.ingredients.fields.minimum_stock_alert'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.001)
                    ->default(0)
                    ->helperText(__('resources.ingredients.helpers.minimum_stock_alert')),
                TextInput::make('slug')
                    ->label(__('resources.ingredients.fields.slug'))
                    ->maxLength(255),
                TextInput::make('name_en')
                    ->label(__('resources.ingredients.fields.english_name'))
                    ->maxLength(255),
                TextInput::make('inci')
                    ->label(__('resources.ingredients.fields.inci'))
                    ->maxLength(255),
                TextInput::make('inci_naoh')
                    ->label(__('resources.ingredients.fields.inci_naoh'))
                    ->maxLength(255),
                TextInput::make('inci_koh')
                    ->label(__('resources.ingredients.fields.inci_koh'))
                    ->maxLength(255),
                TextInput::make('cas')
                    ->label(__('resources.ingredients.fields.cas'))
                    ->maxLength(255),
                TextInput::make('cas_einecs')
                    ->label(__('resources.ingredients.fields.cas_einecs'))
                    ->maxLength(255),
                TextInput::make('einecs')
                    ->label(__('resources.ingredients.fields.einecs'))
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label(__('resources.ingredients.fields.is_active'))
                    ->required(),
                Toggle::make('is_manufactured')
                    ->label(__('resources.ingredients.fields.manufactured_ingredient'))
                    ->helperText(__('resources.ingredients.helpers.manufactured_ingredient'))
                    ->default(false),
                Toggle::make('is_packaging')
                    ->label(__('resources.ingredients.fields.packaging'))
                    ->helperText(__('resources.ingredients.helpers.packaging'))
                    ->default(false),
                Textarea::make('description')
                    ->label(__('resources.ingredients.fields.description'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ingredient_category.name')
                    ->label(__('resources.ingredients.table.category'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('resources.ingredients.table.name'))
                    ->searchable(),
                TextColumn::make('code')
                    ->label(__('resources.ingredients.table.code'))
                    ->searchable(),
                TextColumn::make('base_unit')
                    ->label(__('resources.ingredients.table.unit'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('price')
                    ->label(__('resources.ingredients.table.last_price'))
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('stock_min')
                    ->label(__('resources.ingredients.table.minimum_stock'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('resources.ingredients.table.slug'))
                    ->searchable(),
                TextColumn::make('name_en')
                    ->label(__('resources.ingredients.table.english_name'))
                    ->searchable(),
                TextColumn::make('inci')
                    ->label(__('resources.ingredients.table.inci'))
                    ->searchable(),
                TextColumn::make('inci_naoh')
                    ->label(__('resources.ingredients.table.inci_naoh'))
                    ->searchable(),
                TextColumn::make('inci_koh')
                    ->label(__('resources.ingredients.table.inci_koh'))
                    ->searchable(),
                TextColumn::make('cas')
                    ->label(__('resources.ingredients.table.cas'))
                    ->searchable(),
                TextColumn::make('cas_einecs')
                    ->label(__('resources.ingredients.table.cas_einecs'))
                    ->searchable(),
                TextColumn::make('einecs')
                    ->label(__('resources.ingredients.table.einecs'))
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('resources.ingredients.table.is_active'))
                    ->boolean(),
                IconColumn::make('is_manufactured')
                    ->label(__('resources.ingredients.table.manufactured'))
                    ->boolean(),
                TextColumn::make('deleted_at')
                    ->label(__('resources.ingredients.table.deleted_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('resources.ingredients.table.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('resources.ingredients.table.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('ingredient_category_id')
                    ->label(__('resources.ingredients.fields.category'))
                    ->relationship('ingredient_category', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()->action(function ($data, $record) {
                        if ($record->supplier_listings()->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title(__('resources.ingredients.notifications.delete_blocked_title'))
                                ->body(__('resources.ingredients.notifications.delete_blocked_body', ['name' => $record->name]))
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('resources.ingredients.notifications.deleted_title'))
                            ->body(__('resources.ingredients.notifications.deleted_body', ['name' => $record->name]))
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
            'index' => ListIngredients::route('/'),
            'create' => CreateIngredient::route('/create'),
            'edit' => EditIngredient::route('/{record}/edit'),
        ];
    }

    /**
     * Generate an ingredient code preview based on category.
     */
    private static function generateIngredientCodePreview(?int $categoryId): ?string
    {
        if (! $categoryId) {
            return null;
        }

        $category = IngredientCategory::query()->find($categoryId);

        if (! $category || empty($category->code)) {
            return null;
        }

        $nextSequence = self::getNextSequenceForCategory($category->code);

        return $category->code.str_pad((string) $nextSequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate an ingredient code based on category.
     */
    private static function generateIngredientCode(?int $categoryId): string
    {
        if (! $categoryId) {
            return 'ING'.str_pad((string) (Ingredient::max('id') + 1), 4, '0', STR_PAD_LEFT);
        }

        $category = IngredientCategory::query()->find($categoryId);

        if (! $category || empty($category->code)) {
            return 'ING'.str_pad((string) (Ingredient::max('id') + 1), 4, '0', STR_PAD_LEFT);
        }

        $nextSequence = self::getNextSequenceForCategory($category->code);

        return $category->code.str_pad((string) $nextSequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get the next sequence number for a category code.
     */
    private static function getNextSequenceForCategory(string $categoryCode): int
    {
        $maxSequence = Ingredient::query()
            ->where('code', 'like', $categoryCode.'%')
            ->get()
            ->map(function ($ingredient) use ($categoryCode) {
                // Extract numeric part after category code
                if (str_starts_with($ingredient->code, $categoryCode)) {
                    $numericPart = substr($ingredient->code, strlen($categoryCode));
                    if (is_numeric($numericPart)) {
                        return (int) $numericPart;
                    }
                }

                return 0;
            })
            ->max() ?? 0;

        return $maxSequence + 1;
    }
}
