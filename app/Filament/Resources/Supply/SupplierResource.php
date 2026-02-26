<?php

namespace App\Filament\Resources\Supply;

use App\Filament\Resources\Supply\SupplierResource\Pages\CreateSupplier;
use App\Filament\Resources\Supply\SupplierResource\Pages\EditSupplier;
use App\Filament\Resources\Supply\SupplierResource\Pages\ListSuppliers;
use App\Filament\Resources\Supply\SupplierResource\Pages\ViewSupplier;
use App\Filament\Resources\Supply\SupplierResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Supply\SupplierResource\RelationManagers\SupplierListingsRelationManager;
use App\Models\Supply\Supplier;
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
use Illuminate\Support\Str;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Achats';

    protected static ?string $navigationLabel = 'Fournisseurs';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
           // ->schema([
                // Forms\Components\Group::make()
            ->components([
                Section::make('Détails Fournisseur')
                    ->schema([
                        TextInput::make('name')
                            ->label('Raison Sociale')
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
                            ->label('Slug')
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
                            ->label('Code Client')
                            ->maxLength(100)
                            ->columnSpan(2),

                        Toggle::make('is_active')
                            ->label('Actif')
                            ->inline(false)
                            ->columnSpan(2),

                        TextInput::make('address1')
                            ->label('Adresse')
                            ->maxLength(100)
                            ->columnSpan(3),

                        TextInput::make('address2')
                            ->label('Complément d\'adresse')
                            ->maxLength(100)
                            ->columnSpan(3),

                        TextInput::make('zipcode')
                            ->label('Code Postal')
                            ->maxLength(10)
                            ->columnSpan(2),

                        TextInput::make('country')
                            ->label('Pays')
                            ->maxLength(255)
                            ->columnSpan(4),

                        TextInput::make('email')
                            ->email()
                            ->maxLength(100)
                            ->columnSpan(2),

                        TextInput::make('phone')
                            ->tel()
                            ->telRegex('/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/')
                            ->label('Téléphone')
                            ->maxLength(15)
                            ->columnSpan(2),

                        TextInput::make('Site Internet')
                            ->url()
                            ->maxLength(100)
                            ->columnSpan(2),
                    ])->columns(6),

                // Forms\Components\Group::make()
                // ->schema([
                Section::make('Notes')
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
                    ->label('Raison Sociale')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('address1')
                    ->label('Adresse')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('address2')
                    ->label('Complément d\'adresse')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('zipcode')
                    ->label('Code Postal')
                    ->searchable(),

                TextColumn::make('country')
                    ->label('Pays')
                    ->searchable(),

                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('phone')
                    ->label('Téléphone')
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
                                    ->title('Opération Impossible')
                                    ->body('Supprimez les fichiers liés à ce fournisseur pour le supprimer.')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Fournisseur Supprimé')
                                ->body('Le Fournisseur a été supprimé avec succès.')
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

                Section::make('Détails Fournisseur')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Raison Sociale')
                            ->columnSpan(2),

                        TextEntry::make('code')
                            ->columnSpan(1),

                        TextEntry::make('customer_code')
                            ->columnSpan(1),

                        TextEntry::make('address1')
                            ->label('Adresse'),

                        TextEntry::make('address2')
                            ->label('Adresse Complément'),

                        TextEntry::make('zipcode')
                            ->label('Code Postal'),

                        TextEntry::make('city')
                            ->label('VIlle'),

                        TextEntry::make('country')
                            ->label('Pays'),

                        TextEntry::make('email'),

                        TextEntry::make('phone')
                            ->label('Téléphone'),

                        TextEntry::make('website')
                            ->label('Site Internet'),

                    ])->columns(4),

                Section::make('Notes')
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
}
