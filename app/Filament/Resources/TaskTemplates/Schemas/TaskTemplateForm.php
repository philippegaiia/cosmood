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
                Section::make('Informations générales')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(255),
                        Select::make('product_type_id')
                            ->label('Type de produit')
                            ->relationship('productType', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Laisser vide pour un modèle global'),
                        Toggle::make('is_default')
                            ->label('Modèle par défaut')
                            ->default(false)
                            ->helperText('Utilisé automatiquement pour les nouvelles productions'),
                    ])
                    ->columnSpan(3),

                Section::make('Tâches')
                    ->schema([
                        Repeater::make('taskTypes')
                            ->hiddenLabel()
                            ->relationship(
                                'taskTypes',
                                fn ($query) => $query->orderByPivot('sort_order')
                            )
                            ->schema([
                                Select::make('id')
                                    ->label('Type de tâche')
                                    ->relationship('taskTypes', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->columnSpan(2)
                                    ->helperText(function ($state) {
                                        if (! $state) {
                                            return null;
                                        }
                                        $type = ProductionTaskType::find($state);

                                        return $type ? 'Durée par défaut: '.$type->duration.' min' : null;
                                    }),
                                TextInput::make('duration_override')
                                    ->label('Durée (minutes)')
                                    ->numeric()
                                    ->nullable()
                                    ->minValue(5)
                                    ->step(5)
                                    ->suffix('min')
                                    ->helperText('Laisser vide pour utiliser la durée par défaut du type'),
                                TextInput::make('offset_days')
                                    ->label('Décalage (jours)')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->helperText('Jours après le début de production'),
                                Toggle::make('skip_weekends')
                                    ->label('Ignorer week-ends')
                                    ->default(true)
                                    ->helperText('Reporter les tâches tombant le week-end'),
                                TextInput::make('sort_order')
                                    ->label('Ordre')
                                    ->numeric()
                                    ->default(0)
                                    ->hidden(),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->orderColumn('sort_order')
                            ->itemLabel(function (array $state): string {
                                if (empty($state['id'])) {
                                    return 'Nouvelle tâche';
                                }
                                $type = ProductionTaskType::find($state['id']);

                                return $type ? $type->name : 'Nouvelle tâche';
                            }),
                    ])
                    ->description('Sélectionnez les types de tâches à exécuter pour chaque production utilisant ce modèle.')
                    ->columnSpanFull(),
            ]);
    }
}
