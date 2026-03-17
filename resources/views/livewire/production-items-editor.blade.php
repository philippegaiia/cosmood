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
                    $requiredQty = ((float) ($item['required_quantity'] ?? 0) > 0)
                        ? (float) $item['required_quantity']
                        : $this->calculateQuantity($item);
                    $allocatedQty = $item['total_allocated'] ?? 0;
                    $isPartial = $allocationStatus === App\Enums\AllocationStatus::Partial;
                    $isTakenCareOf = !empty($item['is_procurement_covered']);
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
                                    {{ $this->formatCoefficient($item, $item['percentage_of_oils']) }}
                                </flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs text-zinc-500">Quantité requise</flux:text>
                                <flux:text class="font-semibold">
                                    {{ $this->formatQuantity($item, $requiredQty) }} {{ $this->getUnitSuffix($item) }}
                                </flux:text>
                            </div>
                            <div class="col-span-2">
                                <flux:text class="text-xs text-zinc-500">Allocation</flux:text>
                                @if($hasAllocation)
                                    @foreach($item['allocations'] as $allocation)
                                        <div class="flex items-center gap-2">
                                            <flux:text class="font-medium">{{ $allocation['supply_batch_number'] ?? 'N/A' }}</flux:text>
                                            <flux:text class="text-zinc-500">({{ $this->formatQuantity($item, $allocation['quantity']) }})</flux:text>
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
                                        {{ __('Allouer') }}
                                    </flux:menu.item>
                                    @if(!empty($item['has_reserved_allocations']))
                                        <flux:menu.item wire:click="deallocateItem({{ $index }})" wire:confirm="Désallouer cet item ?" icon="x-mark" color="warning">
                                            Désallouer
                                        </flux:menu.item>
                                    @endif
                                    @if(!empty($item['is_order_marked']))
                                        <flux:menu.item wire:click="unmarkItemOrdered({{ $index }})" icon="minus-circle" color="warning">
                                            {{ __('Retirer marque commande') }}
                                        </flux:menu.item>
                                    @else
                                        <flux:menu.item wire:click="markItemOrdered({{ $index }})" icon="check-circle" color="info">
                                            {{ __('Marquer commande') }}
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

                    @if($isPartial && empty($item['has_split_children']))
                        <flux:callout icon="exclamation-triangle" color="amber" class="mt-3">
                            <flux:callout.text>
                                <strong>{{ __('Allocation partielle:') }}</strong>
                                {{ $this->formatQuantity($item, $allocatedQty) }} {{ $this->getUnitSuffix($item) }} {{ __('alloué(s) sur') }}
                                {{ $this->formatQuantity($item, $requiredQty) }} {{ $this->getUnitSuffix($item) }} {{ __('requis (manque') }}
                                {{ $this->formatQuantity($item, $requiredQty - $allocatedQty) }} {{ $this->getUnitSuffix($item) }})
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    <div class="mt-3 flex gap-2">
                        @if($item['organic'])
                            <flux:badge color="green" size="sm">Bio</flux:badge>
                        @endif
                        @if($isTakenCareOf)
                            <flux:badge color="emerald" size="sm">{{ __('Pris en charge') }}</flux:badge>
                        @endif
                        @if(!empty($item['is_order_marked']))
                            <flux:badge color="blue" size="sm">{{ __('Marque manuelle') }}</flux:badge>
                        @endif
                    </div>

                    <div class="mt-2">
                        @if(!empty($item['is_order_marked']))
                            <flux:button wire:click="unmarkItemOrdered({{ $index }})" variant="ghost" size="xs">
                                {{ __('Retirer marque commande') }}
                            </flux:button>
                        @else
                            <flux:button wire:click="markItemOrdered({{ $index }})" variant="ghost" size="xs">
                                {{ __('Marquer commande') }}
                            </flux:button>
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
        @if($editingItem !== null)
            @php
                $currentItem = $editingItem;
                $isEditingExistingItem = $editingIndex !== null && !empty($items[$editingIndex]['id']);
            @endphp
            <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editingIndex !== null ? 'Modifier l\'item' : 'Nouvel item' }}
            </flux:heading>

            <div>
                <flux:label>Ingrédient *</flux:label>
                <flux:select wire:model.live="editingItem.ingredient_id" placeholder="Sélectionner un ingrédient" :disabled="$isEditingExistingItem">
                    @foreach($ingredientOptions as $id => $name)
                        <flux:select.option value="{{ $id }}">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if($isEditingExistingItem)
                    <flux:text class="text-xs text-zinc-500 mt-1">{{ __('L\'ingrédient ne peut pas être modifié après création.') }}</flux:text>
                @endif
            </div>

            <div>
                <flux:label>Phase *</flux:label>
                <flux:select wire:model="editingItem.phase" :disabled="$isEditingExistingItem">
                    @foreach($this->phaseOptions as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if($isEditingExistingItem)
                    <flux:text class="text-xs text-zinc-500 mt-1">{{ __('La phase ne peut pas être modifiée après création.') }}</flux:text>
                @endif
            </div>

            <div>
                <flux:label>
                    {{ $currentItem['ingredient_id'] ? $this->getCalculationModeLabel($currentItem) : 'Coefficient' }} *
                </flux:label>
                <flux:input
                    wire:key="coefficient-input-{{ $editingIndex ?? 'new' }}-{{ $currentItem['ingredient_id'] ?? 'none' }}-{{ $isEditingExistingItem ? 'edit' : 'create' }}"
                    wire:model.live="editingItem.percentage_of_oils"
                    type="number" 
                    step="{{ $this->getCoefficientInputStep($currentItem) }}" 
                    min="0" 
                    :readonly="!empty($currentItem['ingredient_id']) && $this->isQuantityPerUnit($currentItem) && $isEditingExistingItem"
                />
                @if(!empty($currentItem['ingredient_id']))
                    <flux:text class="text-xs text-zinc-500 mt-1">{{ __('Mode') }}: {{ $this->getCalculationModeLabel($currentItem) }} · {{ __('Unité') }}: {{ $this->getUnitSuffix($currentItem) }}</flux:text>
                @endif
                @if(!empty($currentItem['ingredient_id']) && $this->isQuantityPerUnit($currentItem) && $isEditingExistingItem)
                    <flux:text class="text-xs text-zinc-500 mt-1">Valeur déterminée par l'ingrédient</flux:text>
                @endif
            </div>

            @if(!empty($currentItem['ingredient_id']))
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
                                        {{ $supply['supplier_name'] ?? 'N/A' }} | {{ $supply['batch_number'] }} | {{ $supply['delivery_date'] ?? '-' }} | {{ $supply['wave_name'] ? 'Vague: '.$supply['wave_name'] : 'Vague: -' }} — dispo: {{ number_format($supply['available'], 3) }} {{ $supply['unit'] }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                        @if(!empty($selectedSupplyId))
                            @php
                                $editAvailability = $this->checkAvailability($currentItem);
                            @endphp
                            @if(!$editAvailability['is_sufficient'])
                                <flux:callout icon="exclamation-triangle" color="amber" class="mt-2">
                                    <flux:callout.text>
                                        {{ __('Stock insuffisant: besoin de') }} {{ $this->formatQuantity($currentItem, $editAvailability['required']) }} {{ $this->getUnitSuffix($currentItem) }},
                                        {{ __('disponible') }} {{ $this->formatQuantity($currentItem, $editAvailability['available']) }} {{ $this->getUnitSuffix($currentItem) }}.
                                        {{ __('Allocation partielle possible.') }}
                                    </flux:callout.text>
                                </flux:callout>
                            @else
                                <flux:callout icon="check-circle" color="green" class="mt-2">
                                    <flux:callout.text>
                                        Stock suffisant ({{ $this->formatQuantity($currentItem, $editAvailability['available']) }} {{ $editAvailability['unit'] }})
                                    </flux:callout.text>
                                </flux:callout>
                            @endif
                        @endif
                    @endif
                </div>
            @endif

            @if(!empty($currentItem['ingredient_id']))
                <flux:callout icon="calculator" color="zinc">
                    <flux:callout.heading>Quantité calculée</flux:callout.heading>
                    <flux:callout.text>
                        <strong>{{ $this->formatQuantity($currentItem, $this->calculateQuantity($currentItem)) }} {{ $this->getUnitSuffix($currentItem) }}</strong>
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
