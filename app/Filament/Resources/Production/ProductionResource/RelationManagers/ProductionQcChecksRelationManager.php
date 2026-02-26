<?php

namespace App\Filament\Resources\Production\ProductionResource\RelationManagers;

use App\Enums\QcInputType;
use App\Models\Production\ProductionQcCheck;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ProductionQcChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'productionQcChecks';

    protected static ?string $title = 'Contrôles QC';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('label')
                    ->label('Contrôle')
                    ->searchable(),
                TextColumn::make('completion')
                    ->label('Statut')
                    ->badge()
                    ->state(fn (ProductionQcCheck $record): string => $record->getCompletionLabel())
                    ->color(fn (ProductionQcCheck $record): string|array|null => $record->getCompletionColor()),
                TextColumn::make('input_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (ProductionQcCheck $record): string => $record->input_type?->getLabel() ?? '-'),
                TextColumn::make('limits')
                    ->label('Tolérance')
                    ->state(function (ProductionQcCheck $record): string {
                        if ($record->min_value === null && $record->max_value === null) {
                            return '-';
                        }

                        return trim(($record->min_value ?? '-').' - '.($record->max_value ?? '-').' '.($record->unit ?? ''));
                    }),
                TextColumn::make('target_value')
                    ->label('Cible')
                    ->placeholder('-'),
                TextColumn::make('measured_value')
                    ->label('Mesure')
                    ->state(fn (ProductionQcCheck $record): string => $record->getDisplayValue() ?? '-'),
                TextColumn::make('result')
                    ->label('Résultat')
                    ->badge()
                    ->color(fn (ProductionQcCheck $record): string|array|null => $record->result?->getColor())
                    ->formatStateUsing(fn (ProductionQcCheck $record): string => $record->result?->getLabel() ?? '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('checkedBy.name')
                    ->label('Contrôlé par')
                    ->placeholder('-'),
                TextColumn::make('checked_at')
                    ->label('Contrôlé le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
            ])
            ->recordActions([
                Action::make('markDone')
                    ->label('Marquer fait')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (ProductionQcCheck $record): bool => ! $record->isDone())
                    ->action(function (ProductionQcCheck $record): void {
                        $record->update([
                            'checked_by' => Auth::id(),
                            'checked_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Contrôle QC marqué comme fait')
                            ->success()
                            ->send();
                    }),
                Action::make('recordResult')
                    ->label('Saisir')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        Hidden::make('input_type'),
                        TextInput::make('value_number')
                            ->label('Valeur numérique')
                            ->numeric()
                            ->step(0.001)
                            ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Number->value),
                        Select::make('value_boolean')
                            ->label('Valeur oui/non')
                            ->options([
                                1 => 'Oui',
                                0 => 'Non',
                            ])
                            ->visible(fn (Get $get): bool => $get('input_type') === QcInputType::Boolean->value),
                        TextInput::make('value_text')
                            ->label('Valeur texte')
                            ->visible(fn (Get $get): bool => in_array($get('input_type'), [QcInputType::Text->value, QcInputType::Select->value], true)),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3),
                    ])
                    ->fillForm(fn (ProductionQcCheck $record): array => [
                        'input_type' => $record->input_type?->value,
                        'value_number' => $record->value_number,
                        'value_boolean' => $record->value_boolean,
                        'value_text' => $record->value_text,
                        'notes' => $record->notes,
                    ])
                    ->action(function (ProductionQcCheck $record, array $data): void {
                        $record->update([
                            'value_number' => $data['value_number'] ?? null,
                            'value_boolean' => array_key_exists('value_boolean', $data) && $data['value_boolean'] !== null
                                ? filter_var($data['value_boolean'], FILTER_VALIDATE_BOOLEAN)
                                : null,
                            'value_text' => $data['value_text'] ?? null,
                            'notes' => $data['notes'] ?? null,
                            'checked_by' => Auth::id(),
                            'checked_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Contrôle QC mis à jour')
                            ->success()
                            ->send();
                    }),
                Action::make('markUndone')
                    ->label('Marquer non fait')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (ProductionQcCheck $record): bool => $record->isDone())
                    ->requiresConfirmation()
                    ->action(function (ProductionQcCheck $record): void {
                        $record->update([
                            'value_number' => null,
                            'value_boolean' => null,
                            'value_text' => null,
                            'checked_by' => null,
                            'checked_at' => null,
                        ]);

                        Notification::make()
                            ->title('Contrôle QC repassé en non fait')
                            ->warning()
                            ->send();
                    }),
            ])
            ->defaultSort('sort_order');
    }
}
