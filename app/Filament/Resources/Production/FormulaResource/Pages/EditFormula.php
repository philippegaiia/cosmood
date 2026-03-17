<?php

namespace App\Filament\Resources\Production\FormulaResource\Pages;

use App\Enums\Phases;
use App\Filament\Resources\Production\FormulaResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditFormula extends EditRecord
{
    protected static string $resource = FormulaResource::class;

    public static bool $formActionsAreSticky = true;

    private const SAPONIFIED_EXPECTED_TOTAL = 100.0;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            FormulaResource::makeDuplicateAction(),
            DeleteAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->requiresConfirmation(fn (): bool => $this->shouldConfirmSaponifiedTotalMismatch())
                ->modalHeading(__('formula.confirm_saponified_total'))
                ->modalDescription(fn (): string => $this->getSaponifiedConfirmationDescription())
                ->modalSubmitActionLabel(__('filament::components/modal.actions.confirm'))
                ->modalCancelActionLabel(__('filament::components/modal.actions.cancel')),
            $this->getCancelFormAction(),
        ];
    }

    private function getSaponifiedConfirmationDescription(): string
    {
        $total = number_format($this->getSaponifiedTotalFromState(), 2);

        return __('formula.saponified_total_mismatch_body', ['total' => $total])."\n\n".__('formula.saponified_total_mismatch_hint')."\n\n".__('formula.confirm_continue');
    }

    private function shouldConfirmSaponifiedTotalMismatch(): bool
    {
        if (! $this->shouldApplySaponifiedControl()) {
            return false;
        }

        return abs($this->getSaponifiedTotalFromState() - self::SAPONIFIED_EXPECTED_TOTAL) >= 0.01;
    }

    private function shouldApplySaponifiedControl(): bool
    {
        return (bool) ($this->data['is_soap'] ?? $this->record->is_soap ?? false);
    }

    private function getSaponifiedTotalFromState(): float
    {
        $total = 0.0;

        foreach (($this->data['formulaItems'] ?? []) as $item) {
            if (($item['phase'] ?? null) !== Phases::Saponification->value) {
                continue;
            }

            $total += (float) ($item['percentage_of_oils'] ?? 0);
        }

        return $total;
    }
}
