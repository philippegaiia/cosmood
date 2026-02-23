<?php

namespace App\Models\Production;

use App\Enums\QcInputType;
use App\Enums\QcResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionQcCheck extends Model
{
    /** @use HasFactory<\Database\Factories\Production\ProductionQcCheckFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_type' => QcInputType::class,
            'result' => QcResult::class,
            'required' => 'boolean',
            'value_boolean' => 'boolean',
            'checked_at' => 'datetime',
            'min_value' => 'decimal:3',
            'max_value' => 'decimal:3',
            'value_number' => 'decimal:3',
            'sort_order' => 'integer',
            'options' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductionQcCheck $check): void {
            $check->result = $check->evaluateResult();

            if ($check->hasMeasuredValue() && $check->checked_at === null) {
                $check->checked_at = now();
            }
        });
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(QcTemplateItem::class, 'qc_template_item_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function hasMeasuredValue(): bool
    {
        return $this->value_number !== null || $this->value_boolean !== null || filled($this->value_text);
    }

    public function evaluateResult(): QcResult
    {
        if (! $this->required && ! $this->hasMeasuredValue()) {
            return QcResult::NotApplicable;
        }

        if (! $this->hasMeasuredValue()) {
            return QcResult::Pending;
        }

        return match ($this->input_type) {
            QcInputType::Number => $this->evaluateNumericResult(),
            QcInputType::Boolean => $this->evaluateBooleanResult(),
            QcInputType::Text, QcInputType::Select => $this->evaluateTextResult(),
        };
    }

    public function getDisplayValue(): ?string
    {
        return match ($this->input_type) {
            QcInputType::Number => $this->value_number !== null
                ? number_format((float) $this->value_number, 3, '.', '').($this->unit ? ' '.$this->unit : '')
                : null,
            QcInputType::Boolean => $this->value_boolean === null ? null : ($this->value_boolean ? 'Oui' : 'Non'),
            QcInputType::Text, QcInputType::Select => $this->value_text,
        };
    }

    public function getStageLabel(): string
    {
        return match ($this->stage) {
            'in_process' => 'En process',
            'packaging' => 'Conditionnement',
            default => 'Libération',
        };
    }

    public function isDone(): bool
    {
        return $this->checked_at !== null || $this->hasMeasuredValue();
    }

    public function getCompletionLabel(): string
    {
        return $this->isDone() ? 'Fait' : 'Non fait';
    }

    public function getCompletionColor(): string|array|null
    {
        return $this->isDone() ? 'success' : 'warning';
    }

    protected function evaluateNumericResult(): QcResult
    {
        $value = $this->value_number;

        if ($value === null) {
            return QcResult::Pending;
        }

        if ($this->min_value !== null && (float) $value < (float) $this->min_value) {
            return QcResult::Fail;
        }

        if ($this->max_value !== null && (float) $value > (float) $this->max_value) {
            return QcResult::Fail;
        }

        if ($this->target_value !== null && is_numeric($this->target_value) && (float) $value !== (float) $this->target_value) {
            return QcResult::Fail;
        }

        return QcResult::Pass;
    }

    protected function evaluateBooleanResult(): QcResult
    {
        if ($this->value_boolean === null) {
            return QcResult::Pending;
        }

        if ($this->target_value === null) {
            return $this->value_boolean ? QcResult::Pass : QcResult::Fail;
        }

        return $this->value_boolean === filter_var($this->target_value, FILTER_VALIDATE_BOOLEAN)
            ? QcResult::Pass
            : QcResult::Fail;
    }

    protected function evaluateTextResult(): QcResult
    {
        if (! filled($this->value_text)) {
            return QcResult::Pending;
        }

        if ($this->target_value !== null && mb_strtolower((string) $this->value_text) !== mb_strtolower((string) $this->target_value)) {
            return QcResult::Fail;
        }

        return QcResult::Pass;
    }
}
