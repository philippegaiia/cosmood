<?php

namespace App\Filament\Resources\Supply\SupplierResource\RelationManagers;

use App\Enums\Packaging;
use App\Models\Supply\Ingredient;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplierListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplier_listings';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([Select::make('ingredient_id')
                ->relationship('ingredient', 'name')
                ->options(Ingredient::all()->pluck('name', 'id'))
                ->preload()
                ->searchable()
                ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->maxLength(255),
                TextInput::make('supplier_code')
                    ->maxLength(255),
                Select::make('pkg')
                    ->options(Packaging::class),
                TextInput::make('unit_weight')
                    ->numeric(),
                TextInput::make('price')
                    ->numeric()
                    ->prefix('€'),
                Toggle::make('organic')
                    ->required(),
                Toggle::make('fairtrade')
                    ->required(),
                Toggle::make('cosmos')
                    ->required(),
                Toggle::make('ecocert')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('unit_weight')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('price')
                    ->money()
                    ->sortable(),
                IconColumn::make('organic')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])

            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
