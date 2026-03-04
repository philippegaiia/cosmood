<?php

namespace App\Livewire;

use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Settings;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supply;
use App\Services\Production\IngredientQuantityCalculator;
use App\Services\Production\MasterbatchService;
use App\Services\Production\ProductionAllocationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
        $production->loadMissing('productionItems.ingredient', 'productionItems.allocations.supply');

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
                'supplier_name' => $this->formatSupplierName($supply['id']),
                'delivery_date' => $supply['delivery_date'],
                'wave_name' => null,
            ]));

        // Cache results
        $this->availableSuppliesCache[$ingredientId] = $supplies;

        return $supplies;
    }

    private function formatSupplierName(int $supplyId): string
    {
        $supply = Supply::with('supplierListing.supplier')->find($supplyId);

        if (! $supply) {
            return 'N/A';
        }

        if ($supply->source_production_id !== null) {
            return Settings::internalSupplierLabel();
        }

        $supplier = $supply->supplierListing?->supplier;

        return $supplier ? Str::limit($supplier->name, 8) : 'N/A';
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

    public function updatedSelectedIngredientId(mixed $value): void
    {
        $value = $value ? (int) $value : null;

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

        $itemData = [
            'production_id' => $production->id,
            'ingredient_id' => $this->editingItem['ingredient_id'],
            'phase' => $this->editingItem['phase'],
            'percentage_of_oils' => (float) $this->editingItem['percentage_of_oils'],
            'calculation_mode' => $this->editingItem['calculation_mode'],
            'organic' => $this->editingItem['organic'] ?? false,
            'required_quantity' => $this->calculateQuantity($this->editingItem),
            'sort' => $this->editingIndex ?? count($this->items),
            'supply_id' => $this->editingItem['supply_id'] ?? null,
            'is_supplied' => ! empty($this->editingItem['supply_id']),
        ];

        $savedItem = null;

        if ($this->editingIndex !== null && ! empty($this->items[$this->editingIndex]['id'])) {
            $savedItem = ProductionItem::find($this->items[$this->editingIndex]['id']);
            $savedItem->update($itemData);
        } else {
            $savedItem = ProductionItem::create($itemData);
        }

        $this->syncAllocations($savedItem, $this->editingItem['supply_id'] ?? null);

        $this->loadItems($production);

        $this->closeModal();
        $this->dispatch('item-saved');
    }

    private function syncAllocations(ProductionItem $item, ?int $newSupplyId): void
    {
        $this->allocationService->releaseAll($item);

        if ($newSupplyId === null) {
            // Deallocation - check if it's a split item that should be merged
            if ($item->isSplitChild()) {
                Notification::make()
                    ->title('Désallocation')
                    ->body('Cet item est un item divisé. Voulez-vous le fusionner avec l\'item parent ?')
                    ->warning()
                    ->actions([
                        Action::make('merge')
                            ->label('Fusionner avec parent')
                            ->button()
                            ->color('primary')
                            ->dispatch('mergeSplitItem', ['index' => $this->editingIndex]),
                        Action::make('keep')
                            ->label('Garder séparé')
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

                // Clear cache for this ingredient
                unset($this->availableSuppliesCache[$item->ingredient_id]);

                Notification::make()
                    ->title('Allocation partielle')
                    ->body(sprintf(
                        'Stock insuffisant. Alloué %.3f kg sur %.3f kg requis (manque: %.3f kg).',
                        (float) $allocation->quantity,
                        $requiredQty,
                        $shortage,
                    ))
                    ->warning()
                    ->actions([
                        Action::make('split')
                            ->label('Créer item pour le reste')
                            ->button()
                            ->color('primary')
                            ->dispatch('createSplitForItem', ['index' => $this->editingIndex]),
                        Action::make('dismiss')
                            ->label('Ignorer')
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
    public function createSplitForItem(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $item = ProductionItem::find($this->items[$index]['id']);

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
                ->title('Item divisé')
                ->body(sprintf(
                    'Nouvel item créé avec coefficient %.5f.',
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
    public function mergeSplitItem(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $item = ProductionItem::find($this->items[$index]['id']);

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

            Notification::make()
                ->title('Item fusionné')
                ->body(sprintf(
                    'Item fusionné avec parent. Nouveau coefficient: %.5f.',
                    $parentItem->percentage_of_oils,
                ))
                ->success()
                ->send();
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
                Notification::make()
                    ->title('Item désalloué')
                    ->body('Cet item est un item divisé. Voulez-vous le fusionner avec l\'item parent ?')
                    ->success()
                    ->actions([
                        Action::make('merge')
                            ->label('Fusionner avec parent')
                            ->button()
                            ->color('primary')
                            ->dispatch('mergeSplitItem', ['index' => $index]),
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
                $this->allocationService->releaseAll($productionItem);
                $productionItem->delete();
            }
        }

        unset($this->items[$index]);
        $this->items = array_values($this->items);

        foreach ($this->items as $i => &$item) {
            $item['sort'] = $i;
        }

        $this->dispatch('item-removed');
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

    public function render(): View
    {
        return view('livewire.production-items-editor');
    }
}
