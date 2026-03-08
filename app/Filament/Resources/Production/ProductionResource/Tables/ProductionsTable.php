<?php

namespace App\Filament\Resources\Production\ProductionResource\Tables;

use App\Enums\ProductionStatus;
use App\Models\Production\Production;
use App\Services\Production\PermanentBatchNumberService;
use App\Services\Production\PlanningBatchNumberService;
use App\Services\Production\ProductionStatusTransitionService;
use App\Services\Production\StatusColorScheme;
use App\Services\Production\WaveProductionPlanningService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * Productions table configuration.
 *
 * This class encapsulates all table-related configuration for the Production resource,
 * following Filament v5 best practices of extracting table definitions from resources.
 */
class ProductionsTable
{
    /**
     * Configure the productions table.
     *
     * @param  Table  $table  The table instance to configure
     * @return Table The configured table
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('production_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('permanent_batch_number')
                    ->label('Batch permanent')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('batch_number')
                    ->label('Batch planif')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('wave.name')
                    ->label('Vague')
                    ->badge()
                    ->placeholder('Autonome')
                    ->sortable(),
                TextColumn::make('productionLine.name')
                    ->label('Ligne')
                    ->badge()
                    ->placeholder('Non affectée')
                    ->sortable(),
                TextColumn::make('composite_status')
                    ->label('État')
                    ->state(fn (Production $record): string => StatusColorScheme::forProduction($record)['label'])
                    ->badge()
                    ->color(fn (Production $record): string => StatusColorScheme::forProduction($record)['color'])
                    ->icon(fn (Production $record): ?Heroicon => StatusColorScheme::forProduction($record)['icon'])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('status', $direction))
                    ->tooltip(fn (Production $record): string => sprintf(
                        'Statut: %s | Appro: %s',
                        $record->status->getLabel(),
                        $record->getSupplyCoverageLabel()
                    )),
                TextColumn::make('planned_quantity')
                    ->label('Quantité planifiée')
                    ->numeric()
                    ->suffix(' kg')
                    ->sortable(),
                TextColumn::make('expected_units')
                    ->label('Unités attendues')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_masterbatch')
                    ->label('MB')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedBeaker)
                    ->falseIcon(Heroicon::OutlinedMinus),
                IconColumn::make('uses_masterbatch')
                    ->label('Utilise MB')
                    ->boolean()
                    ->getStateUsing(fn (Production $record): bool => $record->masterbatch_lot_id !== null)
                    ->trueIcon(Heroicon::OutlinedLink),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('production_wave_id')
                    ->label('Vague')
                    ->relationship('wave', 'name'),
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(ProductionStatus::class),
                SelectFilter::make('production_line_id')
                    ->label('Ligne')
                    ->relationship('productionLine', 'name'),
            ])
            ->recordActions([
                Action::make('confirmProduction')
                    ->label(__('Confirmer'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Production $record): bool => $record->status === ProductionStatus::Planned)
                    ->action(function (Production $record): void {
                        $summary = app(ProductionStatusTransitionService::class)
                            ->confirmPlannedProductions(collect([$record]));

                        self::sendConfirmationNotification($summary);
                    }),
                Action::make('duplicate')
                    ->label('Dupliquer')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (Production $record) => self::duplicateProduction($record)),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('assignPermanentBatchNumbers')
                    ->label('Attribuer lots permanents')
                    ->icon(Heroicon::OutlinedHashtag)
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => self::assignPermanentBatchNumbers($records)),
                BulkAction::make('printSelectedDocuments')
                    ->label('Imprimer fiches sélectionnées')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (Collection $selectedRecords): string => self::getBulkDocumentsUrl($selectedRecords))
                    ->openUrlInNewTab(),
                BulkAction::make('rescheduleSelected')
                    ->label('Replanifier sélection')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Nouveau départ')
                            ->native(false)
                            ->required()
                            ->default(now()->toDateString()),
                        TextInput::make('fallback_daily_capacity')
                            ->label('Capacité / jour sans ligne')
                            ->numeric()
                            ->minValue(1)
                            ->default(4)
                            ->required(),
                        Toggle::make('skip_weekends')
                            ->label('Ignorer weekends')
                            ->default(true),
                        Toggle::make('skip_holidays')
                            ->label('Ignorer jours fériés')
                            ->default(true),
                    ])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data): void {
                        $summary = app(WaveProductionPlanningService::class)->rescheduleProductions(
                            productions: $records,
                            startDate: (string) $data['start_date'],
                            skipWeekends: (bool) ($data['skip_weekends'] ?? true),
                            skipHolidays: (bool) ($data['skip_holidays'] ?? true),
                            fallbackDailyCapacity: max(1, (int) ($data['fallback_daily_capacity'] ?? 4)),
                        );

                        Notification::make()
                            ->title('Productions replanifiées')
                            ->body(sprintf(
                                '%d replanifiée(s), %d ignorée(s).',
                                (int) $summary['rescheduled_count'],
                                (int) $summary['skipped_count'],
                            ))
                            ->success()
                            ->send();
                    }),
                BulkAction::make('confirmSelected')
                    ->label(__('Confirmer sélection'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $summary = app(ProductionStatusTransitionService::class)
                            ->confirmPlannedProductions($records);

                        self::sendConfirmationNotification($summary);
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(fn (Production $record): bool => $record->status !== ProductionStatus::Finished),
                    ForceDeleteBulkAction::make()
                        ->authorizeIndividualRecords(fn (Production $record): bool => $record->status !== ProductionStatus::Finished),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['productionItems', 'productionLine']))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Duplicate a production record with a new batch number.
     *
     * Creates a copy of the production with reset status and new identifiers.
     *
     * @param  Production  $record  The production to duplicate
     */
    private static function duplicateProduction(Production $record): void
    {
        $duplicate = $record->replicate();
        $duplicate->status = ProductionStatus::Planned;
        $duplicate->actual_units = null;
        $duplicate->permanent_batch_number = null;
        $duplicate->batch_number = app(PlanningBatchNumberService::class)->generateNextReference();
        $duplicate->slug = self::generateDuplicatedSlug($duplicate->batch_number);
        $duplicate->save();

        Notification::make()
            ->title('Production dupliquée')
            ->body('Nouveau batch: '.$duplicate->batch_number)
            ->success()
            ->send();
    }

    /**
     * Generate a unique slug for a duplicated production.
     *
     * Ensures the slug is unique by appending a numeric suffix if necessary.
     *
     * @param  string  $batchNumber  The batch number to base the slug on
     * @return string The unique slug
     */
    private static function generateDuplicatedSlug(string $batchNumber): string
    {
        $base = Str::slug($batchNumber);
        $slug = $base;
        $attempt = 1;

        while (Production::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.str_pad((string) $attempt, 2, '0', STR_PAD_LEFT);
            $attempt++;
        }

        return $slug;
    }

    /**
     * Assign permanent batch numbers to multiple productions.
     *
     * @param  Collection  $records  The productions to process
     */
    private static function assignPermanentBatchNumbers(Collection $records): void
    {
        $assigned = app(PermanentBatchNumberService::class)
            ->assignForProductions($records->pluck('id')->all());

        Notification::make()
            ->title('Lots permanents attribués')
            ->body($assigned.' lot(s) permanent(s) attribué(s).')
            ->success()
            ->send();
    }

    /**
     * @param  array{confirmed: int, skipped: int, failed: int}  $summary
     */
    private static function sendConfirmationNotification(array $summary): void
    {
        $confirmed = (int) ($summary['confirmed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);

        $notification = Notification::make()
            ->title(__('Confirmation productions'))
            ->body(__('Confirmées: :confirmed | Ignorées: :skipped | Erreurs: :failed', [
                'confirmed' => $confirmed,
                'skipped' => $skipped,
                'failed' => $failed,
            ]));

        if ($confirmed > 0 && $failed === 0) {
            $notification->success()->send();

            return;
        }

        if ($failed > 0) {
            $notification->danger()->send();

            return;
        }

        $notification->warning()->send();
    }

    /**
     * Generate the URL for bulk document printing.
     *
     * @param  Collection  $selectedRecords  The productions to include in the print
     * @return string The URL for the bulk documents route
     */
    private static function getBulkDocumentsUrl(Collection $selectedRecords): string
    {
        $ids = $selectedRecords
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->implode(',');

        return route('productions.bulk-documents', ['ids' => $ids]);
    }

    /**
     * Get the Eloquent query without soft deleting scope.
     *
     * This ensures trashed records are included in queries.
     *
     * @param  Builder  $query  The base query
     * @return Builder The modified query
     */
    public static function getEloquentQuery(Builder $query): Builder
    {
        return $query->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }
}
