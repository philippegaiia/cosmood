<x-filament-panels::page>
    @once
        @vite(['resources/css/app.css'])
        @fluxScripts
    @endonce

    <flux:card class="space-y-6">
        <div>
            <flux:heading size="lg">Simulateur Flash</flux:heading>
            <flux:text class="mt-1">Estimation rapide des besoins ingredients et du cout theorique. Donnees non persistantes.</flux:text>
        </div>

        <flux:separator />

        <div class="space-y-3">
            @foreach ($this->lines as $index => $line)
                <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                    <div class="md:col-span-8">
                        <flux:select wire:model.live="lines.{{ $index }}.product_id" placeholder="Selectionner un produit">
                            @foreach ($this->productOptions as $productId => $label)
                                <flux:select.option value="{{ $productId }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="md:col-span-3">
                        <flux:input
                            type="number"
                            min="0"
                            step="1"
                            placeholder="Unites"
                            wire:model.live="lines.{{ $index }}.units"
                        />
                    </div>

                    <div class="md:col-span-1">
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

        @if ($this->warnings !== [])
            <flux:callout variant="warning">
                <flux:callout.heading>Points à vérifier</flux:callout.heading>
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
        <div class="mt-3 grid gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 p-3">
                <flux:text size="sm">Produits</flux:text>
                <div class="text-lg font-semibold">{{ $this->totals['products_count'] }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <flux:text size="sm">Unites totales</flux:text>
                <div class="text-lg font-semibold">{{ number_format((float) $this->totals['total_units'], 0, ',', ' ') }}</div>
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
        <flux:card class="mt-6">
            <flux:heading size="md">Detail par produit</flux:heading>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left">
                            <th class="py-2 pr-4">Produit</th>
                            <th class="py-2 pr-4">Formule</th>
                            <th class="py-2 pr-4">Unites</th>
                            <th class="py-2 pr-4">Poids net (g)</th>
                            <th class="py-2 pr-4">Coeff.</th>
                            <th class="py-2 pr-4">Poids huiles (kg)</th>
                            <th class="py-2">Cout estime (EUR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->productLines as $line)
                            <tr class="border-b border-zinc-100">
                                <td class="py-2 pr-4">{{ $line['product_name'] }}</td>
                                <td class="py-2 pr-4">{{ $line['formula_name'] }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['units'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['net_weight_g'], 0, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['multiplier'], 2, ',', ' ') }}</td>
                                <td class="py-2 pr-4">{{ number_format((float) $line['oils_kg'], 3, ',', ' ') }}</td>
                                <td class="py-2 font-semibold">{{ number_format((float) $line['estimated_cost'], 2, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif
</x-filament-panels::page>
