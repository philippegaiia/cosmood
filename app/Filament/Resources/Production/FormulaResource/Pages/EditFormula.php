<?php

namespace App\Filament\Resources\Production\FormulaResource\Pages;

use App\Enums\Phases;
use App\Filament\Resources\Production\FormulaResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Session;

class EditFormula extends EditRecord
{
    protected static string $resource = FormulaResource::class;

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
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        if (! $this->shouldConfirmSaponifiedTotalMismatch()) {
            $this->clearSaponifiedConfirmationState();

            return;
        }

        $signature = $this->getSaponifiedConfirmationSignature();

        if ($this->hasSaponifiedConfirmation($signature)) {
            $this->clearSaponifiedConfirmationState();

            return;
        }

        $this->storeSaponifiedConfirmation($signature);

        Notification::make()
            ->warning()
            ->title('Total saponifie different de 100%')
            ->body('Le total saponifie est a '.number_format($this->getSaponifiedTotalFromState(), 2, '.', ' ').' %. Cliquez encore sur Enregistrer pour confirmer.')
            ->send();

        throw new Halt;
    }

    private function getSaponifiedConfirmationSessionKey(): string
    {
        return sprintf('formula:saponified-confirm:%s', $this->record->getKey());
    }

    private function getSaponifiedConfirmationSignature(): string
    {
        return implode('|', [
            (string) ($this->record->getKey() ?? 'new'),
            (string) ((int) ($this->data['is_soap'] ?? $this->record->is_soap ?? false)),
            number_format($this->getSaponifiedTotalFromState(), 4, '.', ''),
        ]);
    }

    private function hasSaponifiedConfirmation(string $signature): bool
    {
        return Session::get($this->getSaponifiedConfirmationSessionKey()) === $signature;
    }

    private function storeSaponifiedConfirmation(string $signature): void
    {
        Session::put($this->getSaponifiedConfirmationSessionKey(), $signature);
    }

    private function clearSaponifiedConfirmationState(): void
    {
        Session::forget($this->getSaponifiedConfirmationSessionKey());
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
