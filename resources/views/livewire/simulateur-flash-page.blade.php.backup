<div id="flash-print-area">
    @once
        <style>
            @media print {
                body * {
                    visibility: hidden !important;
                }

                #flash-print-area,
                #flash-print-area * {
                    visibility: visible !important;
                }

                #flash-print-area {
                    position: absolute;
                    left: 0;
                    top: 0;
                    width: 100%;
                    margin: 0;
                    padding: 0;
                }
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
            <div class="min-w-[906px] space-y-3">
                @foreach ($this->lines as $index => $line)
                <div wire:key="line-{{ $line['line_key'] ?? $index }}" class="flex gap-3">
                    <flux:select
                        wire:key="product-select-{{ $line['line_key'] ?? $index }}"
                        wire:model.live="lines.{{ $index }}.product_id"
                        class="w-[400px]"
                        placeholder="Selectionner un produit"
                    >
                        <flux:select.option value="">Selectionner un produit</flux:select.option>
                        @foreach ($this->productOptions as $productId => $label)
                            <flux:select.option wire:key="product-option-{{ $line['line_key'] ?? $index }}-{{ $productId }}" value="{{ $productId }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input
                        wire:key="desired-units-{{ $line['line_key'] ?? $index }}"
                        wire:model.live="lines.{{ $index }}.desired_units"
                        type="number"
                        min="0"
                        step="1"
                        class="w-[80px]"
                        placeholder="Qté"
                    />

                    @if (! empty($line['product_id']))
                        <flux:select
                            wire:key="batch-preset-{{ $line['line_key'] ?? $index }}"
                            wire:model.live="lines.{{ $index }}.batch_size_preset_id"
                            class="w-[350px]"
                            placeholder="Format de batch"
                        >
                            <flux:select.option value="" disabled>Format de batch</flux:select.option>
                            @foreach ($this->getBatchPresetOptionsForLine($index) as $presetId => $label)
                                <flux:select.option wire:key="preset-option-{{ $line['line_key'] ?? $index }}-{{ $presetId }}" value="{{ $presetId }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <div class="w-[350px] flex h-10 items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-400">
                            Selectionnez d'abord un produit
                        </div>
                    @endif

                    <flux:button
                        wire:click="removeLine({{ $index }})"
                        variant="danger"
                        class="w-[40px]"
                    >
                        -
                    </flux:button>
                </div>
                @endforeach

                <div class="flex items-center gap-2 print:hidden">
                    <flux:button wire:click="addLine" variant="filled">Ajouter un produit</flux:button>
                    <flux:button wire:click="recalculate" variant="primary">Recalculer</flux:button>
                    <flux:button type="button" onclick="window.print()" variant="ghost">Print</flux:button>
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
                        <th class="py-2 pr-4">Quantite</th>
                        <th class="py-2 pr-4">Prix indicatif (EUR)</th>
                        <th class="py-2">Cout estime (EUR)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->ingredientTotals as $ingredient)
                        <tr class="border-b border-zinc-100">
                            <td class="py-2 pr-4">{{ $ingredient['ingredient_name'] }}</td>
                            <td class="py-2 pr-4">{{ number_format((float) $ingredient['required_quantity'], 3, ',', ' ') }} {{ $ingredient['base_unit'] }}</td>
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
                            <th class="py-2">Cout par produit (EUR)</th>
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
                                <td class="py-2 font-semibold">{{ number_format((float) $line['cost_per_unit'], 4, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif
</div>
