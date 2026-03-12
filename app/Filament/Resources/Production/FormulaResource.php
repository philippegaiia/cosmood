<?php

namespace App\Filament\Resources\Production;

use App\Enums\FormulaItemCalculationMode;
use App\Enums\IngredientBaseUnit;
use App\Enums\Phases;
use App\Filament\Resources\Production\FormulaResource\Pages\CreateFormula;
use App\Filament\Resources\Production\FormulaResource\Pages\EditFormula;
use App\Filament\Resources\Production\FormulaResource\Pages\ListFormulas;
use App\Filament\Resources\Production\FormulaResource\Pages\ViewFormula;
use App\Models\Production\Formula;
use App\Models\Production\FormulaItem;
use App\Models\Production\Product;
use App\Models\Supply\Ingredient;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FormulaResource extends Resource
{
    protected static ?string $model = Formula::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Produits';

    protected static ?string $navigationLabel = 'Formules';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    public static function form(Schema $schema): Schema
    {

        return $schema
            ->components([
                Section::make('Détails Formule')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->schema([
                        TextInput::make('name')
                            ->unique(Formula::class)
                            ->maxLength(255),

                        TextInput::make('code')
                            ->maxLength(20)
                            ->unique(Formula::class)
                            ->default(fn () => self::generateUniqueFormulaCode())
                            ->required(fn (string $operation): bool => $operation === 'create'),

                        TextInput::make('dip_number')
                            ->maxLength(50),

                        Toggle::make('is_soap')
                            ->label('Savon saponifie (soude/potasse)')
                            ->helperText('Active uniquement le controle 100% pour les vrais savons saponifies. Laisser decoche pour bases deja saponifiees (noodles).'),

                        Fieldset::make('Dates')
                            ->schema([
                                DatePicker::make('date_of_creation')
                                    ->required()
                                    ->default(now())
                                    ->native(false)
                                    ->weekStartsOnMonday(),
                                Toggle::make('is_active')
                                    ->default(true),

                            ])->columnSpanFull(),

                        Section::make('Informations sur la Formule')
                            ->schema([
                                MarkdownEditor::make('description'),
                            ])
                            ->collapsed()
                            ->columnSpanFull(),

                        Section::make('Produits associés')
                            ->columnSpanFull()
                            ->schema([
                                Repeater::make('formulaProducts')
                                    ->hiddenLabel()
                                    ->relationship()
                                    ->schema([
                                        Select::make('product_id')
                                            ->label('Produit')
                                            ->options(Product::all()->pluck('name', 'id'))
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->native(false),
                                        Toggle::make('is_default')
                                            ->label('Formule par défaut')
                                            ->helperText('Cette formule sera utilisée par défaut pour ce produit'),
                                    ])
                                    ->columns(2)
                                    ->defaultItems(0)
                                    ->addActionLabel('Ajouter un produit'),
                            ]),

                    ]),
                Section::make('Composition')
                    ->hiddenOn('create')
                    ->columnSpanFull()
                    ->schema([
                        Fieldset::make('Totaux')
                            ->columnSpanFull()
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ])
                            ->schema([
                                TextEntry::make('total_saponified')
                                    ->label('Total saponifie')
                                    ->state(function (Get $get): string {
                                        $shouldApplyControl = self::shouldApplySaponifiedControl(
                                            (bool) ($get('is_soap') ?? false),
                                        );

                                        if (! $shouldApplyControl) {
                                            return '-';
                                        }

                                        $total = self::calculateSaponifiedTotal($get('formulaItems') ?? []);

                                        return number_format($total, 2, '.', ' ').' %';
                                    })
                                    ->color(function (Get $get): string {
                                        $shouldApplyControl = self::shouldApplySaponifiedControl(
                                            (bool) ($get('is_soap') ?? false),
                                        );

                                        if (! $shouldApplyControl) {
                                            return 'gray';
                                        }

                                        $total = self::calculateSaponifiedTotal($get('formulaItems') ?? []);

                                        return abs($total - 100.0) < 0.01 ? 'success' : 'danger';
                                    })
                                    ->helperText(fn (Get $get): string => self::shouldApplySaponifiedControl(
                                        (bool) ($get('is_soap') ?? false),
                                    )
                                        ? 'Doit etre a 100 % (alerte rouge si ecart).'
                                        : 'Controle desactive: cochez "Savon saponifie" pour activer.'),
                            ]),
                        Section::make('Items Formule')
                            ->columnSpanFull()
                            ->columns(1)
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
                                            ->options(Ingredient::where('is_active', true)->where('is_packaging', false)->pluck('name', 'id'))
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->native(false)
                                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                                if (! $state) {
                                                    return;
                                                }

                                                $ingredient = Ingredient::query()->find((int) $state);

                                                if (! $ingredient) {
                                                    return;
                                                }

                                                $set(
                                                    'calculation_mode',
                                                    ($ingredient->base_unit?->value ?? IngredientBaseUnit::Kg->value) === IngredientBaseUnit::Unit->value
                                                        ? FormulaItemCalculationMode::QuantityPerUnit->value
                                                        : FormulaItemCalculationMode::PercentOfOils->value,
                                                );
                                            })
                                            ->columnSpan([
                                                'default' => 1,
                                                'xl' => 4,
                                            ]),

                                        Select::make('calculation_mode')
                                            ->label('Mode calcul')
                                            ->options(FormulaItemCalculationMode::class)
                                            ->default(FormulaItemCalculationMode::PercentOfOils)
                                            ->native(false)
                                            ->required()
                                            ->columnSpan([
                                                'default' => 1,
                                                'xl' => 2,
                                            ]),

                                        TextInput::make('percentage_of_oils')
                                            ->label(function (Get $get): string {
                                                $phaseState = $get('phase');
                                                $phase = $phaseState instanceof Phases ? $phaseState->value : (string) ($phaseState ?? '');
                                                $modeState = $get('calculation_mode');
                                                $mode = $modeState instanceof FormulaItemCalculationMode
                                                    ? $modeState
                                                    : FormulaItemCalculationMode::tryFrom((string) ($modeState ?? ''))
                                                    ?? ($phase === Phases::Packaging->value
                                                        ? FormulaItemCalculationMode::QuantityPerUnit
                                                        : FormulaItemCalculationMode::PercentOfOils);

                                                return $mode === FormulaItemCalculationMode::QuantityPerUnit
                                                    ? 'Qté / unité'
                                                    : '% d\'huiles';
                                            })
                                            ->postfix(function (Get $get): string {
                                                $phaseState = $get('phase');
                                                $phase = $phaseState instanceof Phases ? $phaseState->value : (string) ($phaseState ?? '');
                                                $modeState = $get('calculation_mode');
                                                $mode = $modeState instanceof FormulaItemCalculationMode
                                                    ? $modeState
                                                    : FormulaItemCalculationMode::tryFrom((string) ($modeState ?? ''))
                                                    ?? ($phase === Phases::Packaging->value
                                                        ? FormulaItemCalculationMode::QuantityPerUnit
                                                        : FormulaItemCalculationMode::PercentOfOils);

                                                return $mode === FormulaItemCalculationMode::QuantityPerUnit ? 'u' : '%';
                                            })
                                            ->numeric()
                                            ->minValue(0)
                                            ->live()
                                            ->dehydrated()
                                            ->afterStateUpdated(function (Set $set, $state) {
                                                $set('percentage_of_total', 'percentage_of_oils');
                                            })
                                            ->default(1)
                                            ->columnSpan([
                                                'default' => 1,
                                                'xl' => 2,
                                            ]),

                                        Select::make('phase')
                                            ->label('Phase')
                                            ->options(Phases::class)
                                            ->default(Phases::Saponification)
                                            ->live()
                                            ->afterStateUpdated(function (Set $set, $state): void {
                                                $phase = $state instanceof Phases ? $state->value : (string) ($state ?? '');

                                                if ($phase === Phases::Packaging->value) {
                                                    $set('calculation_mode', FormulaItemCalculationMode::QuantityPerUnit->value);
                                                }
                                            })
                                            ->native(false)
                                            ->columnSpan([
                                                'default' => 1,
                                                'xl' => 2,
                                            ]),

                                        Toggle::make('organic')
                                            ->label('Bio')
                                            // ->default(true)
                                            ->inline(false)
                                            ->columnSpan([
                                                'default' => 1,
                                                'xl' => 1,
                                            ]),

                                        TextEntry::make('percentage_of_total')
                                            ->label('Total')
                        // ->dehydrated()
                                            ->state(function (Get $get): string {
                                                return number_format($get('percentage_of_oils'), 2);
                                            })
                                            ->columnSpan([
                                                'default' => 1,
                                                'xl' => 1,
                                            ])
                                            ->live(),

                                    ])->columns([
                                        'default' => 1,
                                        'md' => 2,
                                        'xl' => 12,
                                    ])
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
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    self::makeDuplicateAction(),
                    ViewAction::make(),
                    EditAction::make(),
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
            'index' => ListFormulas::route('/'),
            'create' => CreateFormula::route('/create'),
            'view' => ViewFormula::route('/{record}'),
            'edit' => EditFormula::route('/{record}/edit'),
        ];
    }

    private static function calculateSaponifiedTotal(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            if (($item['phase'] ?? null) !== Phases::Saponification->value) {
                continue;
            }

            $total += (float) ($item['percentage_of_oils'] ?? 0);
        }

        return $total;
    }

    private static function shouldApplySaponifiedControl(bool $isSoap): bool
    {
        return $isSoap;
    }

    /**
     * Generate a unique formula code in format FRM-XXXX.
     */
    private static function generateUniqueFormulaCode(): string
    {
        $maxSerial = Formula::withTrashed()
            ->where('code', 'like', 'FRM-%')
            ->get()
            ->map(fn ($f) => (int) str_replace('FRM-', '', $f->code))
            ->max() ?? 0;

        $nextSerial = $maxSerial + 1;

        return 'FRM-'.str_pad((string) $nextSerial, 4, '0', STR_PAD_LEFT);
    }

    public static function makeDuplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Dupliquer')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->requiresConfirmation()
            ->action(function (Formula $record): void {
                self::duplicateFormula($record);
            });
    }

    private static function duplicateFormula(Formula $record): Formula
    {
        $duplicate = DB::transaction(function () use ($record): Formula {
            $duplicate = $record->replicate();
            $duplicate->name = $record->name.' (copie)';
            $duplicate->slug = null;
            $duplicate->code = self::generateUniqueFormulaCode();
            $duplicate->save();

            // Duplicate formula items
            $record->formulaItems()
                ->orderBy('sort')
                ->get()
                ->each(function (FormulaItem $item) use ($duplicate): void {
                    $itemDuplicate = $item->replicate();
                    $itemDuplicate->formula_id = $duplicate->id;
                    $itemDuplicate->save();
                });

            // Duplicate product relationships
            $record->products()
                ->get()
                ->each(function (Product $product) use ($duplicate): void {
                    $duplicate->products()->attach($product->id, [
                        'is_default' => $product->pivot->is_default,
                    ]);
                });

            return $duplicate;
        });

        Notification::make()
            ->title('Formule dupliquee')
            ->body('Nouvelle formule: '.$duplicate->name)
            ->success()
            ->send();

        return $duplicate;
    }
}
