<?php

namespace App\Filament\Resources\Supply;

use App\Filament\Resources\Supply\SupplierResource\Pages\CreateSupplier;
use App\Filament\Resources\Supply\SupplierResource\Pages\EditSupplier;
use App\Filament\Resources\Supply\SupplierResource\Pages\ListSuppliers;
use App\Filament\Resources\Supply\SupplierResource\Pages\ViewSupplier;
use App\Filament\Resources\Supply\SupplierResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Supply\SupplierResource\RelationManagers\SupplierListingsRelationManager;
use App\Models\Supply\Supplier;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Support\Str;

class SupplierResource extends Resource implements CopilotResource, HasKnowledgeBase
{
    protected static ?string $model = Supplier::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.references');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.items.suppliers');
    }

    public static function getDocumentation(): array|string
    {
        return [
            'procurement/suppliers-and-listings',
            'procurement/supplier-orders',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
           // ->schema([
                // Forms\Components\Group::make()
            ->components([
                Section::make(__('Détails Fournisseur'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Raison Sociale'))
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateHydrated(function (TextInput $component, ?string $state) {
                                $component->state(ucwords($state));
                            })
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, ?string $operation) {
                                if ($get('slug') === null) {
                                    $set('slug', Str::slug($state));

                                    return;
                                }
                            })
                            ->unique(Supplier::class, 'name')
                            ->columnSpan(3),

                        TextInput::make('slug')
                            // ->disabledOn('edit')
                            ->label(__('Slug'))
                            ->required()
                            ->dehydrated()
                            ->unique()
                            ->maxLength(100)
                            ->columnSpan(3),

                        TextInput::make('code')
                            ->required()
                            ->unique(Supplier::class, 'code')
                            ->maxLength(3)
                            ->columnSpan(2),

                        TextInput::make('Customer_code')
                            ->label(__('Code Client'))
                            ->maxLength(100)
                            ->columnSpan(2),

                        TextInput::make('estimated_delivery_days')
                            ->label(__('Délai livraison estimé (jours)'))
                            ->numeric()
                            ->default(8)
                            ->minValue(0)
                            ->step(1)
                            ->helperText(__('Valeur utilisée pour préremplir la date de livraison commande.'))
                            ->columnSpan(2),

                        Toggle::make('is_active')
                            ->label(__('Actif'))
                            ->inline(false)
                            ->columnSpan(2),

                        TextInput::make('address1')
                            ->label(__('Adresse'))
                            ->maxLength(100)
                            ->columnSpan(3),

                        TextInput::make('address2')
                            ->label(__('Complément d\'adresse'))
                            ->maxLength(100)
                            ->columnSpan(3),

                        TextInput::make('zipcode')
                            ->label(__('Code Postal'))
                            ->maxLength(10)
                            ->columnSpan(2),

                        TextInput::make('country')
                            ->label(__('Pays'))
                            ->maxLength(255)
                            ->columnSpan(4),

                        TextInput::make('email')
                            ->email()
                            ->maxLength(100)
                            ->columnSpan(2),

                        TextInput::make('phone')
                            ->tel()
                            ->telRegex('/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/')
                            ->label(__('Téléphone'))
                            ->maxLength(15)
                            ->columnSpan(2),

                        TextInput::make('Site Internet')
                            ->url()
                            ->maxLength(100)
                            ->columnSpan(2),
                    ])->columns(6),

                // Forms\Components\Group::make()
                // ->schema([
                Section::make(__('Notes'))
                    ->collapsed()
                    ->schema([

                        MarkdownEditor::make('description')
                            ->columnSpanFull(),
                    ])->columns(6),
                //  ])
            ]);

    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Raison Sociale'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('address1')
                    ->label(__('Adresse'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('address2')
                    ->label(__('Complément d\'adresse'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('zipcode')
                    ->label(__('Code Postal'))
                    ->searchable(),

                TextColumn::make('country')
                    ->label(__('Pays'))
                    ->searchable(),

                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('phone')
                    ->label(__('Téléphone'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('website')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

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
                    ViewAction::make(),
                    DeleteAction::make()
                        ->action(function ($data, $record) {
                            if ($record->contacts()->count() > 0 || $record->supplier_listings()->count() > 0) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('Opération Impossible'))
                                    ->body(__('Supprimez les fichiers liés à ce fournisseur pour le supprimer.'))
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Fournisseur Supprimé'))
                                ->body(__('Le Fournisseur a été supprimé avec succès.'))
                                ->send();

                            $record->delete();

                        }),
                ]),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            SupplierListingsRelationManager::class,
            ContactsRelationManager::class,
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make(__('Détails Fournisseur'))
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('Raison Sociale'))
                            ->columnSpan(2),

                        TextEntry::make('code')
                            ->columnSpan(1),

                        TextEntry::make('customer_code')
                            ->columnSpan(1),

                        TextEntry::make('estimated_delivery_days')
                            ->label(__('Délai livraison (jours)'))
                            ->columnSpan(1),

                        TextEntry::make('address1')
                            ->label(__('Adresse')),

                        TextEntry::make('address2')
                            ->label(__('Adresse Complément')),

                        TextEntry::make('zipcode')
                            ->label(__('Code Postal')),

                        TextEntry::make('city')
                            ->label(__('VIlle')),

                        TextEntry::make('country')
                            ->label(__('Pays')),

                        TextEntry::make('email'),

                        TextEntry::make('phone')
                            ->label(__('Téléphone')),

                        TextEntry::make('website')
                            ->label(__('Site Internet')),

                    ])->columns(4),

                Section::make(__('Notes'))
                    ->collapsed()
                    ->schema([
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->markdown()
                            ->prose(),

                    ]),
            ]);

    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'view' => ViewSupplier::route('/{record}'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }

    public static function copilotResourceDescription(): ?string
    {
        return __('Suppliers are companies that provide ingredients and supplies for production');
    }

    public static function copilotTools(): array
    {
        return [
            CopilotTools\ListSuppliersTool::class,
            CopilotTools\SearchSuppliersTool::class,
            CopilotTools\ViewSupplierTool::class,
        ];
    }
}
