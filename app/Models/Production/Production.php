<?php

namespace App\Models\Production;

use App\Enums\ProductionStatus;
use App\Enums\RequirementStatus;
use App\Enums\SizingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Production extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'organic' => 'boolean',
            'is_masterbatch' => 'boolean',
            'status' => ProductionStatus::class,
            'sizing_mode' => SizingMode::class,
            'planned_quantity' => 'decimal:3',
            'expected_waste_kg' => 'decimal:3',
            'production_date' => 'date',
            'ready_date' => 'date',
        ];
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(ProductionWave::class, 'production_wave_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function batchSizePreset(): BelongsTo
    {
        return $this->belongsTo(BatchSizePreset::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'parent_id');
    }

    public function masterbatchLot(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'masterbatch_lot_id');
    }

    public function usedInProductions(): HasMany
    {
        return $this->hasMany(Production::class, 'masterbatch_lot_id');
    }

    public function productionItems(): HasMany
    {
        return $this->hasMany(ProductionItem::class);
    }

    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }

    public function productionQcChecks(): HasMany
    {
        return $this->hasMany(ProductionQcCheck::class);
    }

    public function ingredientRequirements(): HasMany
    {
        return $this->hasMany(ProductionIngredientRequirement::class);
    }

    public function packagingRequirements(): HasMany
    {
        return $this->hasMany(ProductionPackagingRequirement::class);
    }

    public function isOrphan(): bool
    {
        return $this->production_wave_id === null;
    }

    public function isMasterbatch(): bool
    {
        return $this->replaces_phase !== null;
    }

    public function usesMasterbatch(): bool
    {
        return $this->masterbatch_lot_id !== null;
    }

    public function getSupplyCoverageState(): string
    {
        $requirements = $this->ingredientRequirements;

        if ($requirements->isEmpty()) {
            return 'missing';
        }

        foreach ($requirements as $requirement) {
            if ($requirement->isFulfilledByMasterbatch()) {
                continue;
            }

            if ($requirement->status === RequirementStatus::NotOrdered) {
                return 'missing';
            }
        }

        foreach ($requirements as $requirement) {
            if ($requirement->isFulfilledByMasterbatch()) {
                continue;
            }

            if (in_array($requirement->status, [RequirementStatus::Ordered, RequirementStatus::Confirmed], true)) {
                return 'ordered';
            }
        }

        return 'received';
    }

    public function getSupplyCoverageLabel(): string
    {
        return match ($this->getSupplyCoverageState()) {
            'received' => 'Approvisionné',
            'ordered' => 'Commandé',
            default => 'Manquant',
        };
    }

    public function getSupplyCoverageColor(): string
    {
        return match ($this->getSupplyCoverageState()) {
            'received' => 'success',
            'ordered' => 'warning',
            default => 'danger',
        };
    }
}
