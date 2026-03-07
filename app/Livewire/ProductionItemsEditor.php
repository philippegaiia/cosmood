<?php

namespace App\Livewire;

use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Enums\ProcurementStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use App\Services\Production\IngredientQuantityCalculator;
use App\Services\Production\MasterbatchService;
use App\Services\Production\ProductionAllocationService;
use App\Services\Production\WaveRequirementStatusService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ProductionItemsEditor extends Component
{
    #[Locked]
    public ?int $productionId = null;

    public float $plannedQuantity = 0;

    public float $expectedUnits = 0;

    public array $items = [];

    public array $ingredientOptions = [];

    public ?array $editingItem = null;

    public ?int $editingIndex = null;

    public mixed $selectedSupplyId = null;

    public mixed $selectedIngredientId = null;

    public bool $showEditModal = false;

    public ?array $masterbatchInfo = null;

    public array $collapsedItems = [];

    private array $availableSuppliesCache = [];

    private IngredientQuantityCalculator $calculator;

    private MasterbatchService $masterbatchService;

    private ProductionAllocationService $allocationService;

    public function mount(?int $productionId = null): void
    {
        $this->productionId = $productionId;

        if ($this->productionId === null) {
            return;
        }

        $production = Production::find($this->productionId);
        if ($production === null) {
            return;
        }

        $this->calculator = app(IngredientQuantityCalculator::class);
        $this->masterbatchService = app(MasterbatchService::class);
        $this->allocationService = app(ProductionAllocationService::class);

        $this->plannedQuantity = (float) ($production->planned_quantity ?? 0);
        $this->expectedUnits = (float) ($production->expected_units ?? 0);

        $this->ingredientOptions = Ingredient::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        $this->loadMasterbatchInfo($production);
        $this->loadItems($production);
    }

    public function boot(): void
    {
        $this->calculator = app(IngredientQuantityCalculator::class);
        $this->masterbatchService = app(MasterbatchService::class);
        $this->allocationService = app(ProductionAllocationService::class);
    }

    private function loadMasterbatchInfo(Production $production): void
    {
        if (! $production->masterbatch_lot_id) {
            $this->masterbatchInfo = null;
            $this->collapsedItems = [];

            return;
        }

        $production->loadMissing('masterbatchLot');

        $masterbatch = $production->masterbatchLot;

        if (! $masterbatch) {
            $this->masterbatchInfo = null;
            $this->collapsedItems = [];

            return;
        }

        $replacedPhase = $masterbatch->replaces_phase;

        $this->masterbatchInfo = [
            'id' => $masterbatch->id,
            'batch_number' => $masterbatch->batch_number,
            'permanent_batch_number' => $masterbatch->permanent_batch_number,
            'replaces_phase' => $replacedPhase,
            'replaces_phase_label' => Phases::tryFrom($replacedPhase)?->getLabel() ?? '-',
        ];

        $this->collapsedItems = $this->masterbatchService
            ->getCollapsedRequirements($production)
            ->map(fn ($req): array => [
                'ingredient_id' => $req->ingredient_id,
                'ingredient_name' => $req->ingredient?->name,
                'phase' => $req->phase,
                'required_quantity' => (float) $req->required_quantity,
            ])
            ->all();
    }

    private function loadItems(Production $production): void
    {
        $production->loadMissing('productionItems.ingredient', 'productionItems.allocations.supply', 'productionItems.splitChildren');

        $replacedPhase = $this->masterbatchInfo['replaces_phase'] ?? null;

        $this->items = $production->productionItems
            ->sortBy('sort')
            ->filter(fn (ProductionItem $item): bool => $replacedPhase === null || $item->phase !== $replacedPhase)
            ->map(fn (ProductionItem $item): array => $this->mapItemToArray($item))
            ->values()
            ->all();
    }

    private function mapItemToArray(ProductionItem $item): array
    {
        $allocations = $item->allocations->filter(fn ($a) => $a->isActive());

        $primaryAllocation = $allocations->first();

        return [
            'id' => $item->id,
            'ingredient_id' => $item->ingredient_id,
            'ingredient_name' => $item->ingredient?->name,
            'phase' => $item->phase,
            'percentage_of_oils' => (float) $item->percentage_of_oils,
            'calculation_mode' => $item->calculation_mode,
            'organic' => (bool) $item->organic,
            'required_quantity' => (float) $item->required_quantity,
            'is_order_marked' => (bool) $item->is_order_marked,
            'allocation_status' => $item->allocation_status?->value,
            'allocations' => $allocations->map(fn ($a) => [
                'id' => $a->id,
                'supply_id' => $a->supply_id,
                'supply_batch_number' => $a->supply?->batch_number,
                'quantity' => (float) $a->quantity,
                'status' => $a->status,
            ])->all(),
            'supply_id' => $primaryAllocation?->supply_id,
            'supply_batch_number' => $primaryAllocation?->supply?->batch_number,
            'total_allocated' => (float) $item->getTotalAllocatedQuantity(),
            'sort' => (int) $item->sort,
            'split_from_item_id' => $item->split_from_item_id,
            'split_root_item_id' => $item->split_root_item_id,
            'has_split_children' => $item->splitChildren->isNotEmpty(),
            'has_reserved_allocations' => $allocations->contains(fn ($allocation) => $allocation->status === 'reserved'),
            'has_consumed_allocations' => $allocations->contains(fn ($allocation) => $allocation->status === 'consumed'),
        ];
    }

    #[Computed]
    public function phaseOptions(): array
    {
        return collect(Phases::cases())
            ->mapWithKeys(fn (Phases $phase): array => [
                $phase->value => $phase->getLabel(),
            ])
            ->all();
    }

    #[Computed]
    public function availableSupplies(): Collection
    {
        if (empty($this->editingItem['ingredient_id'])) {
            return collect();
        }

        $ingredientId = $this->editingItem['ingredient_id'];

        // Check cache
        if (isset($this->availableSuppliesCache[$ingredientId])) {
            return $this->availableSuppliesCache[$ingredientId];
        }

        $ingredient = Ingredient::find($ingredientId);
        if (! $ingredient) {
            return collect();
        }

        $supplies = $this->allocationService->getAvailableSupplies($ingredient)
            ->map(fn ($supply): array => array_merge($supply, [
                'unit' => 'kg',
                'supplier_name' => (string) ($supply['supplier_name'] ?? __('N/A')),
                'delivery_date' => $supply['delivery_date'] ?? null,
                'wave_name' => $supply['wave_label'] ?? null,
            ]));

        // Cache results
        $this->availableSuppliesCache[$ingredientId] = $supplies;

        return $supplies;
    }

    public function calculateQuantity(array $item): float
    {
        $ingredient = Ingredient::find($item['ingredient_id'] ?? 0);
        $mode = $this->calculator->resolveCalculationMode(
            ingredientBaseUnit: $ingredient?->base_unit,
            storedMode: $item['calculation_mode'] ?? null,
        );

        return $this->calculator->calculate(
            coefficient: (float) ($item['percentage_of_oils'] ?? 0),
            batchSizeKg: $this->plannedQuantity,
            expectedUnits: $this->expectedUnits,
            calculationMode: $mode,
        );
    }

    public function checkAvailability(array $item): array
    {
        if (empty($item['supply_id'])) {
            return [
                'is_sufficient' => true,
                'required' => 0.0,
                'available' => 0.0,
                'shortage' => 0.0,
                'unit' => 'kg',
            ];
        }

        $supply = Supply::find($item['supply_id']);
        if (! $supply) {
            return [
                'is_sufficient' => true,
                'required' => 0.0,
                'available' => 0.0,
                'shortage' => 0.0,
                'unit' => 'kg',
            ];
        }

        $required = $this->calculateQuantity($item);

        $available = $supply->getAvailableQuantity();

        if (! empty($item['id'])) {
            $existingItem = ProductionItem::find($item['id']);
            if ($existingItem) {
                $available += $existingItem->getTotalAllocatedQuantity();
            }
        }

        $shortage = max(0, $required - $available);

        return [
            'is_sufficient' => round($shortage, 3) <= 0,
            'required' => round($required, 3),
            'available' => round($available, 3),
            'shortage' => round($shortage, 3),
            'unit' => $supply->getUnitOfMeasure(),
        ];
    }

    public function getCalculationModeLabel(array $item): string
    {
        $ingredient = Ingredient::find($item['ingredient_id'] ?? 0);
        $mode = $this->calculator->resolveCalculationMode(
            ingredientBaseUnit: $ingredient?->base_unit,
            storedMode: $item['calculation_mode'] ?? null,
        );

        return $mode === FormulaItemCalculationMode::QuantityPerUnit
            ? 'Qté / unité'
            : '% d\'huiles';
    }

    public function getUnitSuffix(array $item): string
    {
        $ingredient = Ingredient::find($item['ingredient_id'] ?? 0);
        $mode = $this->calculator->resolveCalculationMode(
            ingredientBaseUnit: $ingredient?->base_unit,
            storedMode: $item['calculation_mode'] ?? null,
        );

        return $mode === FormulaItemCalculationMode::QuantityPerUnit ? 'u' : 'kg';
    }

    public function formatQuantity(array $item, float|int|null $quantity): string
    {
        $normalizedQuantity = (float) ($quantity ?? 0);

        if ($this->isQuantityPerUnit($item)) {
            $roundedQuantity = round($normalizedQuantity, 3);
            $nearestInteger = round($roundedQuantity);

            if (abs($roundedQuantity - $nearestInteger) <= 0.01) {
                return number_format((float) $nearestInteger, 0);
            }

            return number_format($roundedQuantity, 3);
        }

        return number_format($normalizedQuantity, 3);
    }

    public function formatCoefficient(array $item, float|int|null $coefficient): string
    {
        $normalizedCoefficient = (float) ($coefficient ?? 0);

        if ($this->isQuantityPerUnit($item)) {
            $roundedCoefficient = round($normalizedCoefficient, 3);
            $nearestInteger = round($roundedCoefficient);

            if (abs($roundedCoefficient - $nearestInteger) <= 0.01) {
                return number_format((float) $nearestInteger, 0);
            }

            return number_format($roundedCoefficient, 3);
        }

        return number_format($normalizedCoefficient, 3);
    }

    public function getCoefficientInputStep(array $item): string
    {
        return $this->isQuantityPerUnit($item) ? '1' : '0.001';
    }

    public function isQuantityPerUnit(array $item): bool
    {
        $ingredient = Ingredient::find($item['ingredient_id'] ?? 0);
        if (! $ingredient) {
            return false;
        }

        $mode = $this->calculator->resolveCalculationMode(
            ingredientBaseUnit: $ingredient->base_unit,
            storedMode: $item['calculation_mode'] ?? null,
        );

        return $mode === FormulaItemCalculationMode::QuantityPerUnit;
    }

    public function addItem(): void
    {
        $this->editingIndex = null;
        $this->editingItem = [
            'id' => null,
            'ingredient_id' => null,
            'ingredient_name' => null,
            'phase' => Phases::Saponification->value,
            'percentage_of_oils' => 1,
            'calculation_mode' => FormulaItemCalculationMode::PercentOfOils->value,
            'organic' => true,
            'supply_id' => null,
            'sort' => count($this->items),
        ];
        $this->selectedIngredientId = null;
        $this->selectedSupplyId = null;
        $this->showEditModal = true;
    }

    public function editItem(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $this->editingIndex = $index;
        $this->editingItem = $this->items[$index];

        $this->selectedIngredientId = $this->editingItem['ingredient_id'];
        $this->selectedSupplyId = $this->editingItem['supply_id'];

        if (! empty($this->editingItem['ingredient_id'])) {
            $supplies = $this->availableSupplies;

            if ($supplies->count() === 1 && empty($this->selectedSupplyId)) {
                $this->selectedSupplyId = $supplies->first()['id'];
                $this->updatedSelectedSupplyId($this->selectedSupplyId);
            }
        }

        $this->showEditModal = true;
    }

    public function updatedEditingItemIngredientId(mixed $value): void
    {
        $value = $value ? (int) $value : null;

        $this->selectedIngredientId = $value;

        $this->applyIngredientSelection($value);
    }

    public function updatedSelectedIngredientId(mixed $value): void
    {
        $value = $value ? (int) $value : null;

        $this->applyIngredientSelection($value);
    }

    private function applyIngredientSelection(?int $value): void
    {

        if ($this->editingItem === null) {
            return;
        }

        $this->editingItem['ingredient_id'] = $value;

        if (! $value) {
            return;
        }

        $ingredient = Ingredient::find($value);
        if (! $ingredient) {
            return;
        }

        $this->editingItem['ingredient_name'] = $ingredient->name;
        $this->editingItem['supply_id'] = null;

        $this->selectedSupplyId = null;

        $mode = $this->calculator->resolveCalculationMode(
            ingredientBaseUnit: $ingredient->base_unit,
            storedMode: null,
        );
        $this->editingItem['calculation_mode'] = $mode->value;

        if ($mode === FormulaItemCalculationMode::QuantityPerUnit) {
            $this->editingItem['percentage_of_oils'] = 1;
        }

        $supplies = $this->availableSupplies;

        if ($supplies->count() === 1) {
            $this->selectedSupplyId = $supplies->first()['id'];
            $this->updatedSelectedSupplyId($this->selectedSupplyId);
        }
    }

    public function updatedSelectedSupplyId(mixed $value): void
    {
        $value = $value ? (int) $value : null;

        if ($this->editingItem === null) {
            return;
        }

        $this->editingItem['supply_id'] = $value;
    }

    public function saveItem(): void
    {
        if ($this->editingItem === null || $this->productionId === null) {
            return;
        }

        $this->validate([
            'editingItem.ingredient_id' => 'required|exists:ingredients,id',
            'editingItem.phase' => 'required',
            'editingItem.percentage_of_oils' => 'required|numeric|min:0',
        ]);

        $production = Production::find($this->productionId);
        if ($production === null) {
            return;
        }

        $selectedIngredient = Ingredient::find($this->editingItem['ingredient_id']);
        $resolvedCalculationMode = $this->calculator->resolveCalculationMode(
            ingredientBaseUnit: $selectedIngredient?->base_unit,
            storedMode: $this->editingItem['calculation_mode'] ?? null,
        );

        $itemData = [
            'production_id' => $production->id,
            'ingredient_id' => $this->editingItem['ingredient_id'],
            'phase' => $this->editingItem['phase'],
            'percentage_of_oils' => (float) $this->editingItem['percentage_of_oils'],
            'calculation_mode' => $resolvedCalculationMode->value,
            'organic' => $this->editingItem['organic'] ?? false,
            'required_quantity' => $this->calculateQuantity([
                ...$this->editingItem,
                'calculation_mode' => $resolvedCalculationMode->value,
            ]),
            'sort' => $this->editingIndex ?? count($this->items),
            'supply_id' => $this->editingItem['supply_id'] ?? null,
            'is_supplied' => ! empty($this->editingItem['supply_id']),
        ];

        $savedItem = null;

        if ($this->editingIndex !== null && ! empty($this->items[$this->editingIndex]['id'])) {
            $savedItem = ProductionItem::find($this->items[$this->editingIndex]['id']);

            if (! $savedItem) {
                return;
            }

            $hasConsumedAllocations = $savedItem->allocations()
                ->where('status', 'consumed')
                ->exists();

            if ($hasConsumedAllocations) {
                Notification::make()
                    ->title(__('Modification impossible'))
                    ->body(__('Cet item contient des allocations consommées et ne peut plus être modifié.'))
                    ->warning()
                    ->send();

                return;
            }

            $resolvedCalculationMode = $savedItem->calculation_mode?->value ?? $savedItem->calculation_mode;

            $itemData['ingredient_id'] = $savedItem->ingredient_id;
            $itemData['phase'] = $savedItem->phase;
            $itemData['calculation_mode'] = $resolvedCalculationMode;
            $itemData['required_quantity'] = $this->calculateQuantity([
                ...$this->editingItem,
                'ingredient_id' => $savedItem->ingredient_id,
                'calculation_mode' => $resolvedCalculationMode,
            ]);

            $savedItem->update($itemData);
        } else {
            $savedItem = ProductionItem::create($itemData);
        }

        $this->syncAllocations($savedItem, $this->editingItem['supply_id'] ?? null);
        $this->syncWaveRequirementStatusesForProduction($production);

        $this->loadItems($production);

        $this->closeModal();
        $this->dispatch('item-saved');
    }

    private function syncAllocations(ProductionItem $item, ?int $newSupplyId): void
    {
        $hasConsumedAllocations = $item->allocations()
            ->where('status', 'consumed')
            ->exists();

        if ($hasConsumedAllocations) {
            Notification::make()
                ->title(__('Désallocation impossible'))
                ->body(__('Cet item a déjà été consommé et ne peut plus être désalloué.'))
                ->warning()
                ->send();

            return;
        }

        $this->allocationService->releaseAll($item);

        if ($newSupplyId === null) {
            // Deallocation - check if it's a split item that should be merged
            if ($item->isSplitChild()) {
                Notification::make('deallocation-merge-'.$item->id)
                    ->title(__('Désallocation'))
                    ->body(__('Cet item est un item divisé. Voulez-vous le fusionner avec l\'item parent ?'))
                    ->warning()
                    ->actions([
                        Action::make('merge')
                            ->label(__('Fusionner avec parent'))
                            ->button()
                            ->color('primary')
                            ->dispatch('mergeSplitItem', ['itemId' => $item->id])
                            ->close(),
                        Action::make('keep')
                            ->label(__('Garder séparé'))
                            ->close(),
                    ])
                    ->persistent()
                    ->send();
            }

            return;
        }

        $supply = Supply::find($newSupplyId);

        if ($supply === null) {
            return;
        }

        try {
            $allocation = $this->allocationService->allocate($item, $supply);

            $requiredQty = $item->required_quantity ?? $item->getCalculatedQuantityKg();

            if ($allocation->quantity < $requiredQty) {
                $shortage = round($requiredQty - $allocation->quantity, 3);
                $unit = $item->resolveCalculationMode() === FormulaItemCalculationMode::QuantityPerUnit ? 'u' : 'kg';

                // Clear cache for this ingredient
                unset($this->availableSuppliesCache[$item->ingredient_id]);

                Notification::make('partial-allocation-'.$item->id)
                    ->title(__('Allocation partielle'))
                    ->body(sprintf(
                        __('Stock insuffisant. Alloué %.3f %s sur %.3f %s requis (manque: %.3f %s).'),
                        (float) $allocation->quantity,
                        $unit,
                        $requiredQty,
                        $unit,
                        $shortage,
                        $unit,
                    ))
                    ->warning()
                    ->actions([
                        Action::make('split')
                            ->label(__('Créer item pour le reste'))
                            ->button()
                            ->color('primary')
                            ->dispatch('createSplitForItem', ['itemId' => $item->id])
                            ->close(),
                        Action::make('dismiss')
                            ->label(__('Ignorer'))
                            ->close(),
                    ])
                    ->persistent()
                    ->send();
            }
        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->title('Erreur d\'allocation')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    #[On('createSplitForItem')]
    public function createSplitForItem(int $itemId): void
    {
        $item = ProductionItem::find($itemId);

        if (! $item) {
            return;
        }

        try {
            $newItem = $this->allocationService->createSplitItem($item);

            // Clear supply cache for this ingredient
            unset($this->availableSuppliesCache[$item->ingredient_id]);

            // Reload items
            $production = Production::find($this->productionId);
            $this->loadItems($production);

            // Find new item and open edit modal
            $newIndex = collect($this->items)->search(fn ($i) => $i['id'] === $newItem->id);

            Notification::make()
                ->title(__('Item divisé'))
                ->body(sprintf(
                    __('Nouvel item créé avec coefficient %.5f.'),
                    $newItem->percentage_of_oils,
                ))
                ->success()
                ->send();

            if ($newIndex !== false) {
                $this->editItem($newIndex);
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur lors de la division')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    #[On('mergeSplitItem')]
    public function mergeSplitItem(int $itemId): void
    {
        $item = ProductionItem::find($itemId);

        if (! $item) {
            return;
        }

        try {
            $parentItem = $this->allocationService->mergeSplitItem($item);

            // Clear supply cache
            unset($this->availableSuppliesCache[$item->ingredient_id]);

            // Reload items
            $production = Production::find($this->productionId);
            $this->loadItems($production);

            if ($parentItem->id === $itemId) {
                Notification::make()
                    ->title(__('Parent introuvable'))
                    ->body(__('Le parent est introuvable. L\'item a été conservé séparé.'))
                    ->warning()
                    ->send();
            } else {
                Notification::make()
                    ->title(__('Item fusionné'))
                    ->body(sprintf(
                        __('Item fusionné avec parent. Nouveau coefficient: %.5f.'),
                        $parentItem->percentage_of_oils,
                    ))
                    ->success()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur lors de la fusion')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function deallocateItem(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $item = ProductionItem::find($this->items[$index]['id']);

        if (! $item) {
            return;
        }

        try {
            $hasConsumedAllocations = $item->allocations()
                ->where('status', 'consumed')
                ->exists();

            if ($hasConsumedAllocations) {
                Notification::make()
                    ->title(__('Désallocation impossible'))
                    ->body(__('Cet item a déjà été consommé et ne peut plus être désalloué.'))
                    ->warning()
                    ->send();

                return;
            }

            $this->allocationService->releaseAll($item);

            // Clear supply cache for this ingredient
            unset($this->availableSuppliesCache[$item->ingredient_id]);

            // Update item to remove supply reference
            $item->update([
                'supply_id' => null,
                'is_supplied' => false,
            ]);

            // Check if it's a split item and offer to merge
            if ($item->isSplitChild()) {
                Notification::make('deallocated-split-'.$item->id)
                    ->title('Item désalloué')
                    ->body('Cet item est un item divisé. Voulez-vous le fusionner avec l\'item parent ?')
                    ->success()
                    ->actions([
                        Action::make('merge')
                            ->label('Fusionner avec parent')
                            ->button()
                            ->color('primary')
                            ->dispatch('mergeSplitItem', ['itemId' => $item->id])
                            ->close(),
                        Action::make('keep')
                            ->label('Garder séparé')
                            ->close(),
                    ])
                    ->persistent()
                    ->send();
            } else {
                Notification::make()
                    ->title('Item désalloué')
                    ->body('L\'item a été désalloué avec succès.')
                    ->success()
                    ->send();
            }

            // Reload items
            $production = Production::find($this->productionId);
            $this->loadItems($production);
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur lors de la désallocation')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function removeItem(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $item = $this->items[$index];

        if (! empty($item['id'])) {
            $productionItem = ProductionItem::find($item['id']);

            if ($productionItem) {
                $hasActiveAllocations = $productionItem->allocations()
                    ->whereIn('status', ['reserved', 'consumed'])
                    ->exists();

                if ($hasActiveAllocations) {
                    Notification::make()
                        ->title(__('Suppression impossible'))
                        ->body(__('Cet item a des allocations actives. Désallouez-le avant suppression.'))
                        ->warning()
                        ->send();

                    return;
                }

                if ($productionItem->isSplitChild()) {
                    $mergedItem = $this->allocationService->mergeSplitItem($productionItem);

                    if ($mergedItem->id === $productionItem->id) {
                        $productionItem->forceDelete();

                        Notification::make()
                            ->title(__('Item supprimé'))
                            ->body(__('Parent introuvable: item supprimé sans fusion.'))
                            ->warning()
                            ->send();
                    } else {
                        Notification::make()
                            ->title(__('Item fusionné'))
                            ->body(__('L\'item divisé a été fusionné avec son parent.'))
                            ->success()
                            ->send();
                    }
                } elseif ($productionItem->splitChildren()->exists()) {
                    Notification::make()
                        ->title(__('Suppression impossible'))
                        ->body(__('Cet item est parent de divisions. Fusionnez ou supprimez les divisions d\'abord.'))
                        ->warning()
                        ->send();

                    return;
                } else {
                    $productionItem->forceDelete();
                }
            }

            $production = Production::find($this->productionId);

            if ($production) {
                $this->syncWaveRequirementStatusesForProduction($production);
                $this->loadItems($production);
            }

            $this->dispatch('item-removed');

            return;
        }

        unset($this->items[$index]);
        $this->items = array_values($this->items);

        foreach ($this->items as $i => &$item) {
            $item['sort'] = $i;
        }

        $this->dispatch('item-removed');
    }

    public function markItemOrdered(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $item = ProductionItem::query()
            ->with(['production.wave'])
            ->find((int) ($this->items[$index]['id'] ?? 0));

        if (! $item) {
            return;
        }

        $item->update([
            'is_order_marked' => true,
            'procurement_status' => $item->isFullyAllocated()
                ? ProcurementStatus::Received
                : ProcurementStatus::Ordered,
        ]);

        if ($item->production?->wave) {
            app(WaveRequirementStatusService::class)->syncForWave($item->production->wave);
        }

        Notification::make()
            ->title(__('Commande marquée'))
            ->body(__('L\'item est marqué comme commandé.'))
            ->success()
            ->send();

        $production = Production::query()->find($this->productionId);

        if ($production) {
            $this->loadItems($production);
        }
    }

    public function unmarkItemOrdered(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $item = ProductionItem::query()
            ->with(['production.wave'])
            ->find((int) ($this->items[$index]['id'] ?? 0));

        if (! $item) {
            return;
        }

        $item->update([
            'is_order_marked' => false,
            'procurement_status' => $item->isFullyAllocated()
                ? ProcurementStatus::Received
                : ProcurementStatus::NotOrdered,
        ]);

        if ($item->production?->wave) {
            app(WaveRequirementStatusService::class)->syncForWave($item->production->wave);
        }

        Notification::make()
            ->title(__('Marquage retiré'))
            ->body(__('Le marquage commande a été retiré.'))
            ->success()
            ->send();

        $production = Production::query()->find($this->productionId);

        if ($production) {
            $this->loadItems($production);
        }
    }

    public function importMasterbatchTraceability(): void
    {
        if ($this->productionId === null) {
            return;
        }

        $production = Production::find($this->productionId);

        if ($production === null || ! $production->masterbatch_lot_id) {
            Notification::make()
                ->title('Erreur')
                ->body('Aucun masterbatch assigné à cette production.')
                ->danger()
                ->send();

            return;
        }

        $updated = $this->masterbatchService->applyTraceabilityToProductionItems($production);

        $this->syncWaveRequirementStatusesForProduction($production);

        if ($updated === 0) {
            Notification::make()
                ->title('Information')
                ->body('Aucune traçabilité à importer.')
                ->info()
                ->send();

            return;
        }

        Notification::make()
            ->title('Traçabilité importée')
            ->body(sprintf('%d item(s) mis à jour.', $updated))
            ->success()
            ->send();

        $this->loadItems($production);
        $this->dispatch('traceability-imported');
    }

    public function closeModal(): void
    {
        $this->showEditModal = false;
        $this->editingItem = null;
        $this->editingIndex = null;
    }

    private function syncWaveRequirementStatusesForProduction(Production $production): void
    {
        $production->loadMissing('wave');

        if (! $production->wave) {
            return;
        }

        app(WaveRequirementStatusService::class)->syncForWave($production->wave);
    }

    public function render(): View
    {
        return view('livewire.production-items-editor');
    }
}
