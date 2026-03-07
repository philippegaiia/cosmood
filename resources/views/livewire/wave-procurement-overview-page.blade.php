<div class="space-y-6">
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('Pilotage achats - vagues actives') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-600">{{ __('Vue stricte: le besoin restant est calculé uniquement sur le non-alloué.') }}</flux:text>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Rechercher un ingrédient') }}"
                    class="w-72"
                />
                <flux:checkbox wire:model.live="shortageOnly" label="{{ __('Manques uniquement') }}" />
                <flux:button wire:click="reload" variant="ghost">{{ __('Actualiser') }}</flux:button>
            </div>
        </div>

        <details class="rounded-lg border border-zinc-200 bg-zinc-50 p-3">
            <summary class="cursor-pointer text-sm font-medium text-zinc-700">{{ __('Aide colonnes approvisionnement') }}</summary>
            <div class="mt-3 grid gap-2 text-xs text-zinc-600 md:grid-cols-2">
                <div><strong>{{ __('Besoin restant') }}:</strong> {{ __('quantité encore nécessaire (non allouée), y compris items reçus pour la lecture globale.') }}</div>
                <div><strong>{{ __('Couvert (cmd + reçu)') }}:</strong> {{ __('couverture totale par commandes et stock déjà reçu.') }}</div>
                <div><strong>{{ __('Reçu stock') }}:</strong> {{ __('quantité déjà entrée en stock depuis les commandes.') }}</div>
                <div><strong>{{ __('Commandé ferme') }}:</strong> {{ __('commandes ouvertes en statut passé/confirmé/livré.') }}</div>
                <div><strong>{{ __('Brouillon PO') }}:</strong> {{ __('quantités en commande brouillon, non fermes.') }}</div>
                <div><strong>{{ __('Reste à passer') }}:</strong> {{ __('part des besoins encore non commandée.') }}</div>
                <div><strong>{{ __('Engagé PO') }}:</strong> {{ __('quantité explicitement réservée à une vague.') }}</div>
                <div><strong>{{ __('Pool provisoire') }}:</strong> {{ __('commande ouverte non engagée, partageable entre vagues.') }}</div>
                <div><strong>{{ __('Provisoire priorisé') }}:</strong> {{ __('part provisoire affectée par priorité de date de besoin.') }}</div>
                <div><strong>{{ __('Reste à sécuriser') }}:</strong> {{ __('besoin restant après engagement et provisoire priorisé.') }}</div>
                <div><strong>{{ __('Manque indicatif') }}:</strong> {{ __('reste à sécuriser non couvert par le stock disponible.') }}</div>
            </div>
        </details>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-8">
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Besoin restant') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['required_remaining_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Couvert (cmd + reçu)') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['covered_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Reçu stock') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['received_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Commandé ferme') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['firm_order_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Brouillon PO') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['draft_order_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Reste à passer') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['to_order_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Engagé PO') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['committed_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Provisoire priorisé') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['provisional_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Reste à sécuriser') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['to_secure_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Stock dispo (indicatif)') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['stock_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Commandes ouvertes') }}</div>
                <div class="text-lg font-semibold">{{ number_format((float) ($this->summary['open_orders_total'] ?? 0), 3, ',', ' ') }} kg</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-3">
                <div class="text-xs text-zinc-500">{{ __('Manque indicatif') }}</div>
                <div class="text-lg font-semibold {{ ((float) ($this->summary['shortage_total'] ?? 0) > 0) ? 'text-red-600' : 'text-green-600' }}">
                    {{ number_format((float) ($this->summary['shortage_total'] ?? 0), 3, ',', ' ') }} kg
                </div>
            </div>
        </div>
    </flux:card>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500">
                        <th class="px-3 py-2">{{ __('Ingrédient') }}</th>
                        <th class="px-3 py-2">{{ __('Besoin restant') }}</th>
                        <th class="px-3 py-2">{{ __('Couvert (cmd + reçu)') }}</th>
                        <th class="px-3 py-2">{{ __('Reçu stock') }}</th>
                        <th class="px-3 py-2">{{ __('Commandé ferme') }}</th>
                        <th class="px-3 py-2">{{ __('Brouillon PO') }}</th>
                        <th class="px-3 py-2">{{ __('Reste à passer') }}</th>
                        <th class="px-3 py-2">{{ __('Engagé PO') }}</th>
                        <th class="px-3 py-2">{{ __('Pool provisoire') }}</th>
                        <th class="px-3 py-2">{{ __('Provisoire priorisé') }}</th>
                        <th class="px-3 py-2">{{ __('Reste à sécuriser') }}</th>
                        <th class="px-3 py-2">{{ __('Alerte couverture') }}</th>
                        <th class="px-3 py-2">{{ __('Stock dispo') }}</th>
                        <th class="px-3 py-2">{{ __('Commandes ouvertes') }}</th>
                        <th class="px-3 py-2">{{ __('Manque indicatif') }}</th>
                        <th class="px-3 py-2">{{ __('1er besoin') }}</th>
                        <th class="px-3 py-2">{{ __('Vagues') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lines as $line)
                        <tr class="border-b border-zinc-100 align-top">
                            <td class="px-3 py-2 font-medium">{{ $line['ingredient_name'] }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['required_remaining_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['covered_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['received_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['firm_open_order_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['draft_open_order_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['to_order_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['committed_open_order_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['shared_provisional_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['priority_provisional_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['to_secure_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2 text-amber-700">{{ $line['coverage_warning'] ?: '-' }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['stock_advisory'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['open_order_quantity'], 3, ',', ' ') }} kg</td>
                            <td class="px-3 py-2 {{ $line['advisory_shortage'] > 0 ? 'text-red-600 font-medium' : 'text-green-600' }}">
                                {{ number_format((float) $line['advisory_shortage'], 3, ',', ' ') }} kg
                            </td>
                            <td class="px-3 py-2">
                                {{ $line['earliest_need_date'] ? \Illuminate\Support\Carbon::parse($line['earliest_need_date'])->format('d/m/Y') : '-' }}
                            </td>
                            <td class="px-3 py-2">
                                <details>
                                    <summary class="cursor-pointer text-xs text-zinc-600">
                                        {{ __('Voir') }} ({{ $line['waves_count'] }})
                                    </summary>
                                    <div class="mt-2 space-y-1 text-xs">
                                        @foreach ($line['waves'] as $wave)
                                            <div class="rounded border border-zinc-200 p-2">
                                                <div class="font-medium">{{ $wave['wave_name'] }} - {{ $wave['wave_status'] }}</div>
                                                <div class="text-zinc-600">
                                                    {{ __('Besoin') }}: {{ number_format((float) $wave['required_remaining_quantity'], 3, ',', ' ') }} kg |
                                                    {{ __('Couvert') }}: {{ number_format((float) $wave['covered_quantity'], 3, ',', ' ') }} kg |
                                                    {{ __('Reçu') }}: {{ number_format((float) $wave['received_quantity'], 3, ',', ' ') }} kg |
                                                    {{ __('À passer') }}: {{ number_format((float) $wave['to_order_quantity'], 3, ',', ' ') }} kg |
                                                    {{ __('Engagé PO') }}: {{ number_format((float) $wave['committed_open_order_quantity'], 3, ',', ' ') }} kg |
                                                    {{ __('Provisoire') }}: {{ number_format((float) $wave['priority_provisional_quantity'], 3, ',', ' ') }} kg |
                                                    {{ __('À sécuriser') }}: {{ number_format((float) $wave['to_secure_quantity'], 3, ',', ' ') }} kg |
                                                    {{ __('Alerte') }}: {{ $wave['coverage_warning'] ?: '-' }} |
                                                    {{ __('Date') }}: {{ $wave['need_date'] ? \Illuminate\Support\Carbon::parse($wave['need_date'])->format('d/m/Y') : '-' }}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="17" class="px-3 py-6 text-center text-zinc-500">
                                {{ __('Aucune donnée d\'approvisionnement pour les vagues actives.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
