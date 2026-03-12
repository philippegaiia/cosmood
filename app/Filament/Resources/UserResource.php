<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Détails utilisateur'))->schema([

                    TextInput::make('name')
                        ->label(__('Nom'))
                        ->required()
                        ->maxLength(30),

                    TextInput::make('email')
                        ->label(__('E-mail'))
                        ->email()
                        ->required()
                        ->unique()
                        ->maxLength(30),

                    TextInput::make('password')
                        ->label(__('Mot de passe'))
                        ->password()
                        ->required()
                        ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                        ->visible(fn ($livewire) => $livewire instanceof CreateUser)
                        ->rule(Password::default()),

                    Select::make('roles')
                        ->label(__('Rôles'))
                        ->relationship(
                            name: 'roles',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query->orderBy('name')
                        )
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->getOptionLabelFromRecordUsing(fn (Role $record): string => $record->name),
                ]),

                Section::make(__('Nouveau mot de passe'))->schema([
                    TextInput::make('new_password')
                        ->label(__('Nouveau mot de passe'))
                        ->password()
                        ->nullable()
                        ->rule(Password::default()),
                    TextInput::make('new_password_confirmation')
                        ->label(__('Confirmation du mot de passe'))
                        ->same('new_password')
                        ->requiredWith('new_password'),
                ])->visible(fn ($livewire) => $livewire instanceof EditUser),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Nom'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('E-mail'))
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label(__('Rôles'))
                    ->badge()
                    ->separator(', ')
                    ->toggleable(),
                TextColumn::make('email_verified_at')
                    ->label(__('E-mail vérifié le'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Créé le'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('Mis à jour le'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
