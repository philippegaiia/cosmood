<div class="space-y-6" wire:key="{{ $productionId ?? 'new' }}">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="lg">Items de production</flux:heading>
            <flux:text class="mt-1">
                {{ count($items) }} item(s) · Batch: {{ number_format($plannedQuantity, 2) }} kg · {{ number_format($expectedUnits, 0) }} unités
            </flux:text>
        </div>
        <flux:button wire:click="addItem" icon="plus" variant="primary" size="sm">
            Ajouter un item
        </flux:button>
    </div>

    @if($masterbatchInfo)
        <flux:callout icon="beaker" color="blue">
            <flux:callout.heading>
                Masterbatch: {{ $masterbatchInfo['batch_number'] ?? $masterbatchInfo['permanent_batch_number'] ?? 'N/A' }}
            </flux:callout.heading>
            <flux:callout.text>
                Phase <strong>{{ $masterbatchInfo['replaces_phase_label'] }}</strong> remplacée par ce masterbatch.
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="importMasterbatchTraceability" size="sm" variant="ghost">
                    Importer traçabilité MB
                </flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @if(empty($items))
        <flux:callout icon="information-circle" color="zinc">
            <flux:callout.heading>Aucun item de production</flux:callout.heading>
            <flux:callout.text>Cliquez sur "Ajouter un item" pour commencer.</flux:callout.text>
        </flux:callout>
    @else
        @php $renderedGroups = []; @endphp
        <div class="space-y-3">
            @foreach($items as $index => $item)
                @php
                    $allocationStatus = App\Enums\AllocationStatus::tryFrom($item['allocation_status'] ?? null);
                    $hasAllocation = !empty($item['allocations']);
                    $requiredQty = $item['required_quantity'] ?? $this->calculateQuantity($item);
                    $allocatedQty = $item['total_allocated'] ?? 0;
                    $isPartial = $allocationStatus === App\Enums\AllocationStatus::Partial;
                @endphp

                @php
                    $isSplitChild = !empty($item['split_from_item_id']);
                    $splitGroupId = $item['split_root_item_id'] ?? $item['id'];
                @endphp

                @if($isSplitChild && !isset($renderedGroups[$splitGroupId]))
                    @php $renderedGroups[$splitGroupId] = true; @endphp
                    <div class="border-l-4 border-blue-500 pl-4 space-y-3 mb-3">
                        <div class="flex items-center gap-2">
                            <flux:badge color="info" size="sm">
                                Items divisés
                            </flux:badge>
                        </div>
                @endif

                <flux:card class="hover:border-zinc-400 dark:hover:border-zinc-600 transition-colors {{ $isSplitChild ? 'ml-4' : '' }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                            <div>
                                <flux:text class="text-xs text-zinc-500">Ingrédient</flux:text>
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-medium">{{ $item['ingredient_name'] ?? '-' }}</flux:text>
                                    @if($isSplitChild)
                                        <flux:badge color="info" size="sm">
                                            @if($item['split_root_item_id'] == $item['split_from_item_id'])
                                                Divisé depuis #{{ $item['split_from_item_id'] }}
                                            @else
                                                Divisé (branche)
                                            @endif
                                        </flux:badge>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <flux:text class="text-xs text-zinc-500">Phase</flux:text>
                                <flux:badge color="zinc" size="sm">
                                    {{ App\Enums\Phases::tryFrom($item['phase'])?->getLabel() ?? '-' }}
                                </flux:badge>
                            </div>
                            <div>
                                <flux:text class="text-xs text-zinc-500">{{ $this->getCalculationModeLabel($item) }}</flux:text>
                                <flux:text class="font-medium {{ $this->isQuantityPerUnit($item) ? 'text-zinc-600' : '' }}">
                                    {{ number_format($item['percentage_of_oils'], 3) }}
                                </flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs text-zinc-500">Quantité requise</flux:text>
                                <flux:text class="font-semibold">
                                    {{ number_format($requiredQty, 3) }} {{ $this->getUnitSuffix($item) }}
                                </flux:text>
                            </div>
                            <div class="col-span-2">
                                <flux:text class="text-xs text-zinc-500">Allocation</flux:text>
                                @if($hasAllocation)
                                    @foreach($item['allocations'] as $allocation)
                                        <div class="flex items-center gap-2">
                                            <flux:text class="font-medium">{{ $allocation['supply_batch_number'] ?? 'N/A' }}</flux:text>
                                            <flux:text class="text-zinc-500">({{ number_format($allocation['quantity'], 3) }})</flux:text>
                                        </div>
                                    @endforeach
                                @else
                                    <flux:text class="text-zinc-400 italic">Non alloué</flux:text>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            @if($allocationStatus)
                                <flux:badge 
                                    color="{{ $allocationStatus->getColor() }}" 
                                    size="sm"
                                >
                                    {{ $allocationStatus->getLabel() }}
                                </flux:badge>
                            @endif

                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                <flux:menu>
                                    <flux:menu.item wire:click="editItem({{ $index }})" icon="pencil-square">
                                        Modifier
                                    </flux:menu.item>
                                    @if($hasAllocation)
                                        <flux:menu.item wire:click="deallocateItem({{ $index }})" wire:confirm="Désallouer cet item ?" icon="x-mark" color="warning">
                                            Désallouer
                                        </flux:menu.item>
                                    @endif
                                    <flux:menu.separator />
                                    <flux:menu.item wire:click="removeItem({{ $index }})" wire:confirm="Supprimer cet item ?" icon="trash" color="danger">
                                        Supprimer
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>

                    @if($isPartial)
                        <flux:callout icon="exclamation-triangle" color="amber" class="mt-3">
                            <flux:callout.text>
                                <strong>Allocation partielle:</strong> {{ number_format($allocatedQty, 3) }} kg alloué(s) sur {{ number_format($requiredQty, 3) }} kg requis (manque {{ number_format($requiredQty - $allocatedQty, 3) }} kg)
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    <div class="mt-3 flex gap-2">
                        @if($item['organic'])
                            <flux:badge color="green" size="sm">Bio</flux:badge>
                        @endif
                    </div>
                </flux:card>

                @php
                    // Close split group if this is the last item of the group
                    $nextItem = $items[$index + 1] ?? null;
                    $isLastInGroup = !$nextItem || ($nextItem['split_root_item_id'] ?? $nextItem['id']) !== $splitGroupId;
                @endphp

                @if($isSplitChild && $isLastInGroup)
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    <flux:modal wire:model.self="showEditModal" class="max-w-2xl">
        @if($editingItem)
            <div class="space-y-6">
                <flux:heading size="lg">
                    {{ $editingIndex !== null ? 'Modifier l\'item' : 'Nouvel item' }}
                </flux:heading>

                <div>
                    <flux:label>Ingrédient *</flux:label>
                    <flux:select wire:model.live="selectedIngredientId" placeholder="Sélectionner un ingrédient">
                        @foreach($ingredientOptions as $id => $name)
                            <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div>
                    <flux:label>Phase *</flux:label>
                    <flux:select wire:model="editingItem.phase">
                        @foreach($this->phaseOptions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div>
                    <flux:label>
                        {{ $editingItem['ingredient_id'] ? $this->getCalculationModeLabel($editingItem) : 'Coefficient' }} *
                    </flux:label>
                    <flux:input 
                        wire:model="editingItem.percentage_of_oils" 
                        type="number" 
                        step="0.001" 
                        min="0" 
                        @if(!empty($editingItem['ingredient_id']) && $this->isQuantityPerUnit($editingItem) && $editingIndex !== null && !empty($items[$editingIndex]['id'])) readonly @endif
                    />
                    @if(!empty($editingItem['ingredient_id']) && $this->isQuantityPerUnit($editingItem) && $editingIndex !== null && !empty($items[$editingIndex]['id']))
                        <flux:text class="text-xs text-zinc-500 mt-1">Valeur déterminée par l'ingrédient</flux:text>
                    @endif
                </div>

                @if(!empty($editingItem['ingredient_id']))
                    <div>
                        <flux:label>Lot supply (allocation)</flux:label>
                        @php
                            $supplies = $this->availableSupplies;
                        @endphp
                        @if($supplies->isEmpty())
                            <flux:callout icon="exclamation-triangle" color="amber">
                                <flux:callout.heading>Aucun lot disponible</flux:callout.heading>
                                <flux:callout.text>
                                    Aucun lot en stock pour cet ingrédient.
                                </flux:callout.text>
                            </flux:callout>
                        @else
                            <flux:select wire:model.live="selectedSupplyId">
                                <flux:select.option value="">Choisir un lot...</flux:select.option>
                                @foreach($supplies as $supply)
                                    <flux:select.option value="{{ $supply['id'] }}">
                                        {{ $supply['supplier_name'] ?? 'N/A' }} | {{ $supply['batch_number'] }} | {{ $supply['delivery_date'] ?? '-' }} — dispo: {{ number_format($supply['available'], 3) }} {{ $supply['unit'] }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            @if(!empty($selectedSupplyId))
                                @php
                                    $editAvailability = $this->checkAvailability($editingItem);
                                @endphp
                                @if(!$editAvailability['is_sufficient'])
                                    <flux:callout icon="exclamation-triangle" color="amber" class="mt-2">
                                        <flux:callout.text>
                                            Stock insuffisant: besoin de {{ number_format($editAvailability['required'], 3) }}, disponible {{ number_format($editAvailability['available'], 3) }}. Allocation partielle possible.
                                        </flux:callout.text>
                                    </flux:callout>
                                @else
                                    <flux:callout icon="check-circle" color="green" class="mt-2">
                                        <flux:callout.text>
                                            Stock suffisant ({{ number_format($editAvailability['available'], 3) }} {{ $editAvailability['unit'] }})
                                        </flux:callout.text>
                                    </flux:callout>
                                @endif
                            @endif
                        @endif
                    </div>
                @endif

                @if(!empty($editingItem['ingredient_id']))
                    <flux:callout icon="calculator" color="zinc">
                        <flux:callout.heading>Quantité calculée</flux:callout.heading>
                        <flux:callout.text>
                            <strong>{{ number_format($this->calculateQuantity($editingItem), 3) }} {{ $this->getUnitSuffix($editingItem) }}</strong>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div class="flex gap-6">
                    <flux:checkbox wire:model="editingItem.organic" label="Bio" />
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <flux:button wire:click="saveItem" variant="primary">Enregistrer</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
