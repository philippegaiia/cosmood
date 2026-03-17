@php
    $formatQuantity = function (float $quantity, string $unit): string {
        $decimals = $unit === 'u' ? 0 : 2;
        $value = $unit === 'u' ? round($quantity) : $quantity;

        return number_format($value, $decimals, ',', ' ').' '.$unit;
    };

    $signalBadgeClasses = function (string $key): string {
        return match ($key) {
            'order' => 'bg-red-100 text-red-700',
            'commit' => 'bg-amber-100 text-amber-700',
            'allocate' => 'bg-sky-100 text-sky-700',
            'waiting' => 'bg-emerald-100 text-emerald-700',
            default => 'bg-zinc-100 text-zinc-700',
        };
    };
@endphp

<div class="space-y-6">
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="max-w-3xl">
                <flux:heading size="lg">{{ __('Pilotage appro production') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-600">
                    {{ __('Vue planning des productions planifiées, confirmées ou en cours. Les vagues sont regroupées, les lots hors vague restent visibles individuellement, et le stock ainsi que les PO non engagées sont priorisés par date de besoin.') }}
                </flux:text>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Rechercher un ingrédient') }}"
                    class="w-72"
                />
                <flux:checkbox wire:model.live="actionOnly" label="{{ __('À traiter uniquement') }}" />
                <flux:button wire:click="reload" variant="ghost">{{ __('Actualiser') }}</flux:button>
            </div>
        </div>

        <div class="text-sm text-zinc-500">
            {{ __(':ingredients ingrédients à commander | :needs besoins suivis', [
                'ingredients' => (int) ($summary['ingredients_to_order'] ?? 0),
                'needs' => (int) ($summary['contexts_count'] ?? 0),
            ]) }}
        </div>

        <details class="rounded-lg border border-zinc-200 bg-zinc-50 p-3">
            <summary class="cursor-pointer text-sm font-medium text-zinc-700">{{ __('Aide lecture planning') }}</summary>
            <div class="mt-3 grid gap-2 text-xs text-zinc-600 md:grid-cols-2 xl:grid-cols-3">
                <div><strong>{{ __('Besoin total') }}:</strong> {{ __('besoin complet des productions suivies pour l’ingrédient.') }}</div>
                <div><strong>{{ __('Besoin restant') }}:</strong> {{ __('besoin encore à couvrir après allocations déjà faites.') }}</div>
                <div><strong>{{ __('Stock dispo') }}:</strong> {{ __('stock physique moins stock déjà alloué, partagé sur l’ingrédient.') }}</div>
                <div><strong>{{ __('PO non engagées') }}:</strong> {{ __('commandes ouvertes déjà passées mais pas encore réservées à un besoin précis.') }}</div>
                <div><strong>{{ __('Commandes par vague') }}:</strong> {{ __('les commandes déjà liées à une vague restent visibles dans le détail de chaque besoin, pas dans le résumé global.') }}</div>
                <div><strong>{{ __('Détail besoins') }}:</strong> {{ __('chaque besoin montre la part de stock priorisée et la part de PO affectable selon la date la plus proche.') }}</div>
            </div>
        </details>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Besoin total') }}</div>
                <div class="text-lg font-semibold">{{ $summary['total_requirement'] ?? '-' }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Besoin restant') }}</div>
                <div class="text-lg font-semibold">{{ $summary['remaining_requirement'] ?? '-' }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Stock dispo') }}</div>
                <div class="text-lg font-semibold">{{ $summary['available_stock'] ?? '-' }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('PO non engagées') }}</div>
                <div class="text-lg font-semibold">{{ $summary['open_orders_not_committed'] ?? '-' }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('À sécuriser') }}</div>
                <div class="text-lg font-semibold">{{ $summary['remaining_to_secure'] ?? '-' }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('À commander') }}</div>
                <div class="text-lg font-semibold">{{ $summary['remaining_to_order'] ?? '-' }}</div>
            </div>
        </div>
    </flux:card>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="min-w-[104rem] table-fixed text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500">
                        <th class="w-72 px-3 py-2 whitespace-normal">{{ __('Ingrédient') }}</th>
                        <th class="w-32 px-3 py-2 whitespace-normal">{{ __('1er besoin') }}</th>
                        <th class="w-36 px-3 py-2 whitespace-normal">{{ __('Besoin') }}</th>
                        <th class="w-36 px-3 py-2 whitespace-normal">{{ __('Restant') }}</th>
                        <th class="w-36 px-3 py-2 whitespace-normal">{{ __('Stock dispo') }}</th>
                        <th class="w-40 px-3 py-2 whitespace-normal">{{ __('PO non engagées') }}</th>
                        <th class="w-36 px-3 py-2 whitespace-normal">{{ __('À sécuriser') }}</th>
                        <th class="w-36 px-3 py-2 whitespace-normal">{{ __('À commander') }}</th>
                        <th class="w-36 px-3 py-2 whitespace-normal">{{ __('Signal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lines as $line)
                        <tr class="align-top">
                            <td class="px-3 py-2 font-medium">{{ $line['ingredient_name'] }}</td>
                            <td class="px-3 py-2">
                                {{ $line['earliest_need_date'] ? \Illuminate\Support\Carbon::parse($line['earliest_need_date'])->format('d/m/Y') : '-' }}
                            </td>
                            <td class="px-3 py-2">{{ $formatQuantity((float) $line['total_requirement'], (string) $line['display_unit']) }}</td>
                            <td class="px-3 py-2">{{ $formatQuantity((float) $line['remaining_requirement'], (string) $line['display_unit']) }}</td>
                            <td class="px-3 py-2">{{ $formatQuantity((float) $line['available_stock'], (string) $line['display_unit']) }}</td>
                            <td class="px-3 py-2">{{ $formatQuantity((float) $line['open_orders_not_committed'], (string) $line['display_unit']) }}</td>
                            <td class="px-3 py-2">{{ $formatQuantity((float) $line['remaining_to_secure'], (string) $line['display_unit']) }}</td>
                            <td class="px-3 py-2 {{ $line['remaining_to_order'] > 0 ? 'font-medium text-red-600' : 'text-zinc-700' }}">
                                {{ $formatQuantity((float) $line['remaining_to_order'], (string) $line['display_unit']) }}
                            </td>
                            <td class="px-3 py-2">
                                <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $signalBadgeClasses($line['signal']['key']) }}">
                                    {{ $line['signal']['label'] }}
                                </span>
                            </td>
                        </tr>
                        <tr class="border-b border-zinc-100 bg-zinc-50/60">
                            <td colspan="9" class="px-3 py-3">
                                <details class="group">
                                    <summary class="flex cursor-pointer list-none items-center justify-between rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700">
                                        <span>{{ __('Besoins détaillés') }} ({{ $line['contexts_count'] }})</span>
                                        <span class="text-xs font-normal text-zinc-500">{{ __('Voir le détail') }}</span>
                                    </summary>
                                    <div class="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                        @foreach ($line['contexts'] as $context)
                                            <div class="rounded-lg border border-zinc-200 bg-white p-3 text-xs">
                                                <div class="flex flex-wrap items-start justify-between gap-2">
                                                    <div>
                                                        <div class="font-medium text-zinc-800">{{ $context['context_label'] }}</div>
                                                        <div class="text-zinc-500">
                                                            {{ $context['context_type_label'] }} | {{ $context['context_status'] }} | {{ $context['need_date'] ? \Illuminate\Support\Carbon::parse($context['need_date'])->format('d/m/Y') : '-' }}
                                                        </div>
                                                    </div>
                                                    <span class="inline-flex rounded-full px-2 py-1 text-[11px] font-medium {{ $signalBadgeClasses($context['signal']['key']) }}">
                                                        {{ $context['signal']['label'] }}
                                                    </span>
                                                </div>
                                                <div class="mt-3 grid gap-x-4 gap-y-1 text-zinc-600 sm:grid-cols-2">
                                                    <div><span class="font-medium">{{ __('Restant') }}:</span> {{ $formatQuantity((float) $context['remaining_requirement'], (string) $context['display_unit']) }}</div>
                                                    <div><span class="font-medium">{{ __('Stock priorisé') }}:</span> {{ $formatQuantity((float) $context['stock_priority_quantity'], (string) $context['display_unit']) }}</div>
                                                    <div><span class="font-medium">{{ __('Cmd liée') }}:</span> {{ $formatQuantity((float) $context['wave_ordered_quantity'], (string) $context['display_unit']) }}</div>
                                                    <div><span class="font-medium">{{ __('Reçu') }}:</span> {{ $formatQuantity((float) $context['wave_received_quantity'], (string) $context['display_unit']) }}</div>
                                                    <div><span class="font-medium">{{ __('PO affectables') }}:</span> {{ $formatQuantity((float) $context['open_orders_priority_quantity'], (string) $context['display_unit']) }}</div>
                                                    <div><span class="font-medium">{{ __('À sécuriser') }}:</span> {{ $formatQuantity((float) $context['remaining_to_secure'], (string) $context['display_unit']) }}</div>
                                                    <div><span class="font-medium">{{ __('À commander') }}:</span> {{ $formatQuantity((float) $context['remaining_to_order'], (string) $context['display_unit']) }}</div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-6 text-center text-zinc-500">
                                {{ __('Aucun besoin d\'approvisionnement sur les productions suivies.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
