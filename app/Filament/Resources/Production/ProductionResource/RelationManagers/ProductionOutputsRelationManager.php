<?php

namespace App\Filament\Resources\Production\ProductionResource\RelationManagers;

use App\Enums\ProductionOutputKind;
use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionOutput;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductionOutputsRelationManager extends RelationManager
{
    protected static string $relationship = 'productionOutputs';

    protected static ?string $title = 'Sorties';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('kind')
                    ->label(__('Type de sortie'))
                    ->options(fn (?ProductionOutput $record): array => $this->getKindOptions($record))
                    ->required()
                    ->native(false)
                    ->live()
                    ->disabled(fn (?ProductionOutput $record): bool => $record !== null)
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        if ($state === null) {
                            return;
                        }

                        $kind = ProductionOutputKind::tryFrom($state);

                        if (! $kind instanceof ProductionOutputKind) {
                            return;
                        }

                        if ($kind !== ProductionOutputKind::ReworkMaterial) {
                            $set('ingredient_id', null);
                        }

                        if ($get('unit') === null || $get('unit') === '') {
                            $set('unit', $this->getDefaultUnitForKind($kind));
                        }
                    }),
                Select::make('ingredient_id')
                    ->label(__('Ingrédient rebatch fabriqué'))
                    ->relationship(
                        name: 'ingredient',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_manufactured', true),
                    )
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText(__('Sélectionnez un ingrédient fabriqué interne, comme pour un masterbatch ou un macérat.'))
                    ->visible(fn (Get $get): bool => $get('kind') === ProductionOutputKind::ReworkMaterial->value)
                    ->required(fn (Get $get): bool => $get('kind') === ProductionOutputKind::ReworkMaterial->value),
                TextInput::make('quantity')
                    ->label(__('Quantité'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Select::make('unit')
                    ->label(__('Unité'))
                    ->options([
                        'u' => __('u'),
                        'kg' => __('kg'),
                    ])
                    ->required()
                    ->native(false)
                    ->default(fn (): string => $this->getProduction()->getDefaultMainOutputUnit()),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(2)
                    ->maxLength(1000),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('kind')
            ->columns([
                TextColumn::make('kind')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (ProductionOutput $record): string => $record->kind->getLabel())
                    ->color(fn (ProductionOutput $record): string|array|null => $record->kind->getColor()),
                TextColumn::make('target')
                    ->label(__('Sortie'))
                    ->state(fn (ProductionOutput $record): string => $record->getTargetLabel()),
                TextColumn::make('quantity')
                    ->label(__('Quantité'))
                    ->numeric(decimalPlaces: 3)
                    ->suffix(fn (ProductionOutput $record): string => ' '.$record->unit),
                TextColumn::make('notes')
                    ->label(__('Notes'))
                    ->limit(50)
                    ->wrap(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Ajouter une sortie'))
                    ->visible(fn (): bool => ! $this->areOutputsLocked() && $this->canCreateMoreOutputs()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => ! $this->areOutputsLocked()),
                DeleteAction::make()
                    ->visible(fn (): bool => ! $this->areOutputsLocked()),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'ingredient'])->orderBy('kind'))
            ->emptyStateHeading(__('Aucune sortie renseignée'))
            ->emptyStateDescription(__('Déclarez au minimum la sortie principale avant de terminer le lot.'));
    }

    /**
     * @return array<string, string>
     */
    private function getKindOptions(?ProductionOutput $record): array
    {
        $existingKinds = $this->getOwnerRecord()
            ->productionOutputs()
            ->when($record?->exists, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
            ->pluck('kind')
            ->all();

        return collect(ProductionOutputKind::cases())
            ->reject(fn (ProductionOutputKind $kind): bool => in_array($kind->value, $existingKinds, true))
            ->mapWithKeys(fn (ProductionOutputKind $kind): array => [$kind->value => $kind->getLabel()])
            ->all();
    }

    private function getDefaultUnitForKind(ProductionOutputKind $kind): string
    {
        return match ($kind) {
            ProductionOutputKind::MainProduct => $this->getProduction()->getDefaultMainOutputUnit(),
            default => 'kg',
        };
    }

    private function areOutputsLocked(): bool
    {
        $status = $this->getOwnerRecord()->status;

        return in_array($status, [ProductionStatus::Finished, ProductionStatus::Cancelled], true);
    }

    private function canCreateMoreOutputs(): bool
    {
        return $this->getOwnerRecord()->productionOutputs()->count() < count(ProductionOutputKind::cases());
    }

    private function getProduction(): Production
    {
        /** @var Production $ownerRecord */
        $ownerRecord = $this->ownerRecord;

        return $ownerRecord;
    }
}
