<?php

namespace App\Filament\Resources\Production;

use App\Filament\Resources\Production\ProductionTaskTypeResource\Pages\CreateProductionTaskType;
use App\Filament\Resources\Production\ProductionTaskTypeResource\Pages\EditProductionTaskType;
use App\Filament\Resources\Production\ProductionTaskTypeResource\Pages\ListProductionTaskTypes;
use App\Filament\Resources\Production\ProductionTaskTypeResource\Pages\ViewProductionTaskType;
use App\Models\Production\ProductionTaskType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductionTaskTypeResource extends Resource
{
    protected static ?string $model = ProductionTaskType::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Production';

    protected static ?string $navigationLabel = 'Types de tâches';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                ColorPicker::make('color')
                    ->label('Couleur')
                    ->helperText('Couleur dans le calendrier')
                    ->default('#3b82f6'),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255),
                TextInput::make('duration')
                    ->required()
                    ->numeric()
                    ->default(1),
                Textarea::make('description')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                Toggle::make('is_capacity_consuming')
                    ->label(__('Consomme la capacité'))
                    ->helperText(__('Compter cette tâche dans la capacité journalière de la ligne.'))
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('duration')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_capacity_consuming')
                    ->label(__('Capacité'))
                    ->boolean(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
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
            'index' => ListProductionTaskTypes::route('/'),
            'create' => CreateProductionTaskType::route('/create'),
            'view' => ViewProductionTaskType::route('/{record}'),
            'edit' => EditProductionTaskType::route('/{record}/edit'),
        ];
    }
}
