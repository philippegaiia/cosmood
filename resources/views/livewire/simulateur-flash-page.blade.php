<div>
    @once
        <style>
            .flash-sim-grid {
                display: grid;
                grid-template-columns: repeat(12, minmax(0, 1fr));
                gap: 0.75rem;
                min-width: 0;
            }

            .flash-col-1 {
                grid-column: span 1 / span 1;
                min-width: 0;
            }

            .flash-col-2 {
                grid-column: span 2 / span 2;
                min-width: 0;
            }

            .flash-col-3 {
                grid-column: span 3 / span 3;
                min-width: 0;
            }

            .flash-col-4 {
                grid-column: span 4 / span 4;
                min-width: 0;
            }
        </style>
    @endonce

    <flux:card class="space-y-6">
        <div>
            <flux:heading size="lg">Simulateur Flash</flux:heading>
            <flux:text class="mt-1">Simulation par batches fixes (unites / batch et kg huiles / batch). Donnees non persistantes.</flux:text>
        </div>

        <flux:separator />

        <div class="overflow-x-auto">
            <div class="min-w-245 space-y-3">
                @foreach ($this->lines as $index => $line)
                <div wire:key="line-{{ $line['line_key'] ?? $index }}" class="flash-sim-grid">
                    <div class="flash-col-2">
                        <flux:input
                            wire:key="search-{{ $line['line_key'] ?? $index }}"
                            field:class="w-full"
                            type="search"
                            placeholder="Rechercher"
                            wire:model.live.debounce.300ms="lines.{{ $index }}.product_search"
                        />
                    </div>

                    <div class="flash-col-4">
                        <flux:select
                            wire:key="product-select-{{ $line['line_key'] ?? $index }}"
                            field:class="w-full"
                            class="w-full"
                            wire:model.live="lines.{{ $index }}.product_id"
                            placeholder="Selectionner un produit"
                        >
                            @foreach ($this->getFilteredProductOptionsForLine($index) as $productId => $label)
                                <flux:select.option wire:key="product-option-{{ $line['line_key'] ?? $index }}-{{ $productId }}" value="{{ $productId }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flash-col-2">
                        <flux:input
                            wire:key="desired-units-{{ $line['line_key'] ?? $index }}"
                            field:class="w-full"
                            type="number"
                            min="0"
                            step="1"
                            placeholder="Quantite demandee"
                            wire:model.live="lines.{{ $index }}.desired_units"
                        />
                    </div>

                    <div class="flash-col-3">
                        @if (! empty($line['product_id']))
                            <flux:select
                                class="w-full"
                                field:class="w-full"
                                wire:model.live="lines.{{ $index }}.batch_size_preset_id"
                                wire:key="batch-preset-{{ $line['line_key'] ?? $index }}"
                                placeholder="Format de batch"
                            >
                                @foreach ($this->getBatchPresetOptionsForLine($index) as $presetId => $label)
                                    <flux:select.option wire:key="preset-option-{{ $line['line_key'] ?? $index }}-{{ $presetId }}" value="{{ $presetId }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <div class="flex h-10 w-full items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-400">
                                Selectionnez d'abord un produit
                            </div>
                        @endif
                    </div>

                    <div class="flash-col-1">
                        <flux:button variant="danger" wire:click="removeLine({{ $index }})" class="w-full">
                            -
                        </flux:button>
                    </div>
                </div>
                @endforeach

                <div class="flex items-center gap-2">
                    <flux:button wire:click="addLine" variant="filled">Ajouter un produit</flux:button>
                    <flux:button wire:click="recalculate" variant="primary">Recalculer</flux:button>
                </div>
            </div>
        </div>

        @if ($this->warnings !== [])
            <flux:callout variant="warning">
                <flux:callout.heading>Points a verifier</flux:callout.heading>
                <flux:callout.text>
                    @foreach ($this->warnings as $warning)
                        <div>{{ $warning }}</div>
                    @endforeach
                </flux:callout.text>
            </flux:callout>
        @endif
    </flux:card>

    <flux:card class="mt-6">
        <flux:heading size="md">Synthese</flux:heading>
        <div class="mt-3 grid gap-3 md:grid-cols-6">
            <div class="rounded-lg border border-zinc-200 p-3">
                <flux:text size="sm">Produits</flux:text>
                <div class="text-lg font-semibold">{{ $this->totals['products_count'] }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <flux:text size="sm">Quantite demandee</flux:text>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->totals['total_desired_units'] ?? 0), 0, ',', ' ') }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <flux:text size="sm">Quantite produite</flux:text>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->totals['total_produced_units'] ?? 0), 0, ',', ' ') }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <flux:text size="sm">Extra</flux:text>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->totals['total_extra_units'] ?? 0), 0, ',', ' ') }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <flux:text size="sm">Batches</flux:text>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->totals['total_batches'] ?? 0), 0, ',', ' ') }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <flux:text size="sm">Poids huiles estime</flux:text>
                <div class="text-lg font-semibold">{{ number_format((float) $this->totals['total_batch_kg'], 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <flux:text size="sm">Cout estime</flux:text>
                <div class="text-lg font-semibold">{{ number_format((float) $this->totals['total_estimated_cost'], 2, ',', ' ') }} EUR</div>
            </div>
        </div>
    </flux:card>

    <flux:card class="mt-6">
        <flux:heading size="md">Besoins ingredients (comme si tout est commande)</flux:heading>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left">
                        <th class="py-2 pr-4">Ingredient</th>
                        <th class="py-2 pr-4">Quantite (kg)</th>
                        <th class="py-2 pr-4">Prix indicatif (EUR/kg)</th>
                        <th class="py-2">Cout estime (EUR)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->ingredientTotals as $ingredient)
                        <tr class="border-b border-zinc-100">
                            <td class="py-2 pr-4">{{ $ingredient['ingredient_name'] }}</td>
                            <td class="py-2 pr-4">{{ number_format((float) $ingredient['required_kg'], 3, ',', ' ') }}</td>
                            <td class="py-2 pr-4">{{ number_format((float) $ingredient['unit_price'], 2, ',', ' ') }}</td>
                            <td class="py-2 font-semibold">{{ number_format((float) $ingredient['estimated_cost'], 2, ',', ' ') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-3 text-zinc-500">Aucun resultat pour le moment.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

    @if ($this->productLines !== [])
        @php
            $productSummaries = collect($this->productLines)
                ->groupBy('product_id')
                ->map(function ($rows) {
                    $first = $rows->first();

                    return [
                        'product_name' => $first['product_name'],
                        'desired_units' => (float) $rows->sum('desired_units'),
                        'produced_units' => (float) $rows->sum('produced_units'),
                        'extra_units' => (float) $rows->sum('extra_units'),
                        'batches_required' => (int) $rows->sum('batches_required'),
                        'oils_kg' => (float) $rows->sum('oils_kg'),
                    ];
                })
                ->values();
        @endphp

        <flux:card class="mt-6">
            <flux:heading size="md">Extra par produit</flux:heading>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left">
                            <th class="py-2 pr-4">Produit</th>
                            <th class="py-2 pr-4">Demande</th>
                            <th class="py-2 pr-4">Produite</th>
                            <th class="py-2 pr-4">Extra</th>
                            <th class="py-2 pr-4">Batches</th>
                            <th class="py-2">Poids huiles (kg)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($productSummaries as $line)
                            <tr class="border-b border-zinc-100">
                                <td class="py-2 pr-4">{{ $line['product_name'] }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['desired_units'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['produced_units'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4 font-semibold">{{ number_format((float) $line['extra_units'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['batches_required'], 0, ',', ' ') }}</td>
                                <td class="py-2">{{ number_format((float) $line['oils_kg'], 3, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>

        <flux:card class="mt-6">
            <flux:heading size="md">Detail par produit</flux:heading>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left">
                            <th class="py-2 pr-4">Produit</th>
                            <th class="py-2 pr-4">Formule</th>
                            <th class="py-2 pr-4">Demande</th>
                            <th class="py-2 pr-4">Unites / batch</th>
                            <th class="py-2 pr-4">Batches</th>
                            <th class="py-2 pr-4">Prod.</th>
                            <th class="py-2 pr-4">Extra (ligne)</th>
                            <th class="py-2 pr-4">Kg / batch</th>
                            <th class="py-2 pr-4">Poids huiles (kg)</th>
                            <th class="py-2">Cout estime (EUR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->productLines as $line)
                            <tr class="border-b border-zinc-100">
                                <td class="py-2 pr-4">{{ $line['product_name'] }}</td>
                                <td class="py-2 pr-4">{{ $line['formula_name'] }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['desired_units'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['units_per_batch'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['batches_required'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['produced_units'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['extra_units'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['batch_size_kg'], 3, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['oils_kg'], 3, ',', ' ') }}</td>
                                <td class="py-2 font-semibold">{{ number_format((float) $line['estimated_cost'], 2, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif
</div>
