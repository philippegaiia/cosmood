<?php

namespace App\Filament\Resources\TaskTemplates\Schemas;

use App\Models\Production\ProductionTaskType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaskTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Informations générales'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Nom'))
                            ->required()
                            ->maxLength(255),
                        Repeater::make('productTypes')
                            ->label(__('Types de produit'))
                            ->relationship('productTypes')
                            ->schema([
                                Select::make('id')
                                    ->label(__('Type de produit'))
                                    ->relationship('productTypes', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Toggle::make('is_default')
                                    ->label(__('Par défaut'))
                                    ->default(false)
                                    ->helperText(__('Utilisé automatiquement pour les productions de ce type')),
                            ])
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(3),

                Section::make(__('Tâches'))
                    ->schema([
                        Repeater::make('taskTemplateTaskTypes')
                            ->hiddenLabel()
                            ->relationship('taskTemplateTaskTypes')
                            ->schema([
                                Select::make('production_task_type_id')
                                    ->label(__('Type de tâche'))
                                    ->relationship('taskType', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->columnSpan(2)
                                    ->helperText(function ($state) {
                                        if (! $state) {
                                            return null;
                                        }
                                        $type = ProductionTaskType::find($state);

                                        return $type ? __('Durée par défaut: :duration min', ['duration' => $type->duration]) : null;
                                    }),
                                TextInput::make('duration_override')
                                    ->label(__('Durée (minutes)'))
                                    ->numeric()
                                    ->nullable()
                                    ->minValue(5)
                                    ->step(5)
                                    ->suffix('min')
                                    ->helperText(__('Laisser vide pour utiliser la durée par défaut du type')),
                                TextInput::make('offset_days')
                                    ->label(__('Décalage (jours)'))
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->helperText(__('Jours après le début de production')),
                                Toggle::make('skip_weekends')
                                    ->label(__('Ignorer week-ends'))
                                    ->default(true)
                                    ->helperText(__('Reporter les tâches tombant le week-end')),
                                TextInput::make('sort_order')
                                    ->label(__('Ordre'))
                                    ->numeric()
                                    ->default(0)
                                    ->hidden(),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->orderColumn('sort_order')
                            ->itemLabel(function (array $state): string {
                                if (empty($state['production_task_type_id'])) {
                                    return __('Nouvelle tâche');
                                }
                                $type = ProductionTaskType::find($state['production_task_type_id']);

                                return $type ? $type->name : __('Nouvelle tâche');
                            }),
                    ])
                    ->description(__('Sélectionnez les types de tâches à exécuter pour chaque production utilisant ce modèle.'))
                    ->columnSpanFull(),
            ]);
    }
}
