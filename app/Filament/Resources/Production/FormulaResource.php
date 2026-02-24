<?php

namespace App\Filament\Resources\Production;

use App\Enums\Phases;
use App\Filament\Resources\Production\FormulaResource\Pages\CreateFormula;
use App\Filament\Resources\Production\FormulaResource\Pages\EditFormula;
use App\Filament\Resources\Production\FormulaResource\Pages\ListFormulas;
use App\Filament\Resources\Production\FormulaResource\Pages\ViewFormula;
use App\Models\Production\Formula;
use App\Models\Production\Product;
use App\Models\Supply\Ingredient;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FormulaResource extends Resource
{
    protected static ?string $model = Formula::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Produits';

    protected static ?string $navigationLabel = 'Formules';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    public static function form(Schema $schema): Schema
    {

        return $schema
            ->components([
                Section::make('Détails Formule')
                    ->schema([
                        TextInput::make('name')
                            ->unique(Formula::class, ignoreRecord: true)
                            ->maxLength(255),

                        TextInput::make('code')
                            ->maxLength(20)
                            ->disabledOn('edit')
                            ->unique(Formula::class, ignoreRecord: true)
                            ->required(fn (string $operation): bool => $operation === 'create'),

                        Select::make('product_id')
                            ->relationship('product', 'name')
                            ->options(Product::all()->pluck('name', 'id'))
                            ->preload()
                            ->searchable()
                            ->required(),

                        TextInput::make('dip_number')
                            ->maxLength(50),

                        Toggle::make('is_active')
                            ->default(true),

                        Fieldset::make('Dates')
                            ->schema([
                                DatePicker::make('date_of_creation')
                                    ->required()
                                    ->default(now())
                                    ->native(false)
                                    ->weekStartsOnMonday(),

                            ])->columnSpanFull(),

                        Section::make('Informations sur la Formule')
                            ->schema([
                                MarkdownEditor::make('description'),
                            ])
                            ->collapsed()
                            ->columnSpanFull(),

                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                //  ]);
                Section::make()
                    ->hiddenOn('create')
                    ->columns(1)
                    ->columnSpanFull()
                    ->schema([
                        Fieldset::make('Totaux')
                            ->schema([
                                TextEntry::make('total_saponified')
                                    ->state(function ($get) {
                                        $total = 0;

                                        foreach ($get('formulaItems') as $item) {
                                            if ($item['phase'] === Phases::Saponification->value) {
                                                $total += (int) $item['percentage_of_oils'];
                                            }

                                        }

                                        return $total;
                                    }),

                                TextEntry::make('total_formula')
                                    ->state(function ($get) {
                                        $total = 0;
                                        foreach ($get('formulaItems') as $item) {
                                            if (($item['phase'] ?? null) === Phases::Packaging->value) {
                                                continue;
                                            }

                                            $total += (int) $item['percentage_of_oils'];

                                        }
                                        if ($total !== 0) {
                                            $totalformula = 100 / $total;

                                            return $totalformula;
                                        }
                                    }),
                            ]),
                        Section::make('Items Formule')
                            ->schema([
                                Repeater::make('formulaItems')
                                    ->relationship()
                                    ->hiddenOn('create')
                                    ->schema([
                                        Select::make('ingredient_id')
                            /* ->relationship(
                                    name: 'ingredient',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->where('supplier_id', $get('../../supplier_id')),
                                    )*/
                                            ->label('Ingrédient')
                                            ->options(Ingredient::where('is_active', true)->pluck('name', 'id'))
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->native(false)
                                            ->columnSpan(6),

                                        TextInput::make('percentage_of_oils')
                                            ->label(function (Get $get): string {
                                                $phaseState = $get('phase');
                                                $phase = $phaseState instanceof Phases ? $phaseState->value : (string) ($phaseState ?? '');

                                                return $phase === Phases::Packaging->value ? 'Qté / unité' : '% d\'huiles';
                                            })
                                            ->postfix(function (Get $get): string {
                                                $phaseState = $get('phase');
                                                $phase = $phaseState instanceof Phases ? $phaseState->value : (string) ($phaseState ?? '');

                                                return $phase === Phases::Packaging->value ? 'u' : '%';
                                            })
                                            ->numeric()
                                            ->live()
                                            ->dehydrated()
                                            ->afterStateUpdated(function (Set $set, $state) {
                                                $set('percentage_of_total', 'percentage_of_oils');
                                            })
                                            ->default(1)
                                            ->columnSpan(3),

                                        Select::make('phase')
                                            ->label('Phase')
                                            ->options(Phases::class)
                                            ->default(Phases::Saponification)
                                            ->native(false)
                                            ->columnSpan(4),

                                        Toggle::make('organic')
                                            ->label('Bio')
                                            // ->default(true)
                                            ->inline(false)
                                            ->columnSpan(3),

                                        TextEntry::make('percentage_of_total')
                                            ->label('Total')
                        // ->dehydrated()
                                            ->state(function (Get $get): string {
                                                return number_format($get('percentage_of_oils'), 2);
                                            })
                                            ->live(),

                                    ])->columns(18)
                                    ->defaultItems(1)
                                    ->reorderableWithButtons()
                                    ->orderColumn('sort')
                                    ->live(),
                            ]),

                        // Read-only, because it's calculated
                        // ->readOnly()
                        // ->suffix('%')
                        // This enables us to display the subtotal on the edit page load
                        // ->afterStateHydrated(function (Get $get, Set $set) {
                        //    self::updateTotals($get, $set);
                        // })

                        /*  Forms\Components\TextInput::make('Total Formule')
                        ->numeric()
                        // Read-only, because it's calculated
                        ->readOnly()
                        // This enables us to display the subtotal on the edit page load
                        ->afterStateHydrated(function (Get $get, Set $set) {
                            self::updateTotals($get, $set);
                        }), */
                    ]),
            ]);
    }

    /*   public static function updateTotals(Get $get, $livewire): void
       {
           // Retrieve the state path of the form. Most likely, it's `data` but could be something else.
           $statePath = $livewire->getFormStatePath();

           $ingredients = data_get($livewire, $statePath . '.forumula_items');
           if (collect($ingredients)->isEmpty()) {
               return;
           }
           $selectedIngredients = collect($ingredients)->filter(fn ($item) => !empty($item['product_id']) && !empty($item['quantity']));

           $prices = collect($ingredients)->pluck('price', 'product_id');

           $subtotal = $selectedIngredients->reduce(function ($subtotal, $ingredient) use ($prices) {
               return $subtotal + ($prices[$ingredient['product_id']] * $ingredient['quantity']);
           }, 0);

           data_set($livewire, $statePath . '.subtotal', number_format($subtotal, 2, '.', ''));
           data_set($livewire, $statePath . '.total', number_format($subtotal + ($subtotal * (data_get($livewire, $statePath . '.taxes') / 100)), 2, '.', ''));
       }*/

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('code')
                    ->searchable(),

                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->searchable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
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
            'index' => ListFormulas::route('/'),
            'create' => CreateFormula::route('/create'),
            'view' => ViewFormula::route('/{record}'),
            'edit' => EditFormula::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
