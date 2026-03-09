<div
    class="space-y-6"
    x-data="{
        draggingType: null,
        draggingId: null,
        draggingIsDraggable: false,
        dragStart(type, id, isDraggable) {
            if (!isDraggable) return;
            this.draggingType = type;
            this.draggingId = id;
            this.draggingIsDraggable = true;
        },
        dragEnd() {
            this.draggingType = null;
            this.draggingId = null;
            this.draggingIsDraggable = false;
        },
        dragOver(event) {
            if (!this.draggingIsDraggable) return;
            event.preventDefault();
        },
        drop(event, lineId, day) {
            event.preventDefault();
            if (!this.draggingIsDraggable) return;
            if (this.draggingType === 'production') {
                $wire.moveProduction(this.draggingId, lineId, day);
            } else if (this.draggingType === 'task') {
                $wire.moveTask(this.draggingId, day);
            }
            this.dragEnd();
        },
    }"
>
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('Planning board') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-600">
                    {{ __('Vue hebdomadaire basée sur les tâches. La capacité est calculée par lot distinct avec au moins une tâche consommatrice ce jour-là.') }}
                </flux:text>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button wire:click="previousWeek" variant="ghost">{{ __('Semaine précédente') }}</flux:button>
                <div class="rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium">
                    {{ \Illuminate\Support\Carbon::parse($weekStart)->format('d/m/Y') }}
                    -
                    {{ \Illuminate\Support\Carbon::parse($weekStart)->addDays(6)->format('d/m/Y') }}
                </div>
                <flux:button wire:click="nextWeek" variant="ghost">{{ __('Semaine suivante') }}</flux:button>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
            <flux:field>
                <flux:label>{{ __('Ligne') }}</flux:label>
                <flux:select wire:model.live="filterLineId">
                    <option value="">{{ __('Toutes les lignes') }}</option>
                    @foreach ($board['lines'] as $line)
                        @continue($line['id'] === null)
                        <option value="{{ $line['id'] }}">{{ $line['name'] }}</option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Statut') }}</flux:label>
                <flux:select wire:model.live="filterStatus">
                    <option value="">{{ __('Tous') }}</option>
                    <option value="planned">{{ __('Planifiée') }}</option>
                    <option value="confirmed">{{ __('Confirmée') }}</option>
                    <option value="ongoing">{{ __('En cours') }}</option>
                    <option value="finished">{{ __('Terminée') }}</option>
                    <option value="cancelled">{{ __('Annulée') }}</option>
                </flux:select>
            </flux:field>

            <flux:checkbox wire:model.live="showProductions" label="{{ __('Productions') }}" />
            <flux:checkbox wire:model.live="showTasks" label="{{ __('Tâches') }}" />
            <flux:checkbox wire:model.live="onlyIssues" label="{{ __('Problèmes uniquement') }}" />
            <flux:checkbox wire:model.live="onlyUnassigned" label="{{ __('Sans ligne uniquement') }}" />
        </div>
    </flux:card>

    <div class="overflow-x-auto">
        <table class="min-w-full border-separate border-spacing-0 text-sm">
            <thead>
                <tr>
                    <th class="sticky left-0 z-20 min-w-48 border-b border-zinc-200 bg-white px-4 py-3 text-left font-semibold">
                        {{ __('Ligne') }}
                    </th>
                    @foreach ($board['days'] as $day)
                        <th class="min-w-64 border-b border-zinc-200 bg-white px-4 py-3 text-left">
                            <div class="font-semibold">{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('D d/m') }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($board['lines'] as $line)
                    <tr>
                        <th class="sticky left-0 z-10 border-b border-zinc-200 bg-zinc-50 px-4 py-4 text-left align-top">
                            <div class="font-medium text-zinc-900">{{ $line['name'] }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ $line['capacity'] ? __('Capacité :count lots/jour', ['count' => $line['capacity']]) : __('File sans affectation') }}
                            </div>
                        </th>

                        @foreach ($board['days'] as $day)
                            @php($cell = $board['cells'][$line['key']][$day] ?? null)
                            @php($cellBg = $cell['is_over_capacity'] ? ' bg-red-50' : ($cell['is_near_capacity'] ? ' bg-amber-50' : ' bg-white'))
                            @php($cellBgActive = $cell['is_over_capacity'] ? ' bg-red-100' : ($cell['is_near_capacity'] ? ' bg-amber-100' : ' bg-zinc-50'))

                            <td
                                class="border-b border-l border-zinc-200 px-3 py-3 align-top transition-colors duration-100{{ $cellBg }}"
                                @dragover="dragOver($event)"
                                @dragenter.prevent="$el.classList.add('{{ trim($cellBgActive) }}')"
                                @dragleave="$el.classList.remove('{{ trim($cellBgActive) }}')"
                                @drop="$el.classList.remove('{{ trim($cellBgActive) }}'); drop($event, {{ $line['id'] ?? 'null' }}, '{{ $day }}')"
                            >
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $cell['is_over_capacity'] ? 'bg-red-100 text-red-700' : ($cell['is_near_capacity'] ? 'bg-amber-100 text-amber-700' : 'bg-zinc-100 text-zinc-700') }}">
                                            {{ $line['capacity'] ? __(':used/:capacity', ['used' => $cell['used'], 'capacity' => $cell['capacity']]) : __('—') }}
                                        </span>

                                        @if ($cell['has_issue'])
                                            <span class="text-xs font-medium text-amber-700">⚠</span>
                                        @endif
                                    </div>

                                    @if ($showProductions)
                                        <div class="space-y-2">
                                            @foreach ($cell['productions'] as $production)
                                                @php($cardBg = match ($production['status']) {
                                                    'planned'   => 'border-zinc-200 bg-zinc-50',
                                                    'confirmed' => 'border-blue-200 bg-blue-50',
                                                    'ongoing'   => 'border-amber-200 bg-amber-50',
                                                    'finished'  => 'border-emerald-200 bg-emerald-50',
                                                    'cancelled' => 'border-red-200 bg-red-50',
                                                    default     => 'border-zinc-200 bg-white',
                                                })
                                                @php($cardRing = $production['is_line_allowed'] ? '' : ' ring-2 ring-amber-400 ring-offset-1')
                                                @php($cardCursor = $production['is_draggable'] ? 'cursor-grab active:cursor-grabbing' : 'cursor-default opacity-80')

                                                <div
                                                    class="rounded-xl border p-3 select-none {{ $cardBg }}{{ $cardRing }} {{ $cardCursor }}"
                                                    draggable="{{ $production['is_draggable'] ? 'true' : 'false' }}"
                                                    @dragstart="dragStart('production', {{ $production['id'] }}, {{ $production['is_draggable'] ? 'true' : 'false' }})"
                                                    @dragend="dragEnd()"
                                                >
                                                    <div class="flex items-start justify-between gap-3">
                                                        <div>
                                                            <div class="font-medium text-zinc-900">{{ $production['product_name'] }}</div>
                                                            <div class="text-xs text-zinc-500">{{ $production['batch_ref'] }}</div>
                                                        </div>
                                                        <span class="shrink-0 rounded-full bg-white/70 px-2 py-1 text-[11px] uppercase tracking-wide text-zinc-600">
                                                            {{ $production['status'] }}
                                                        </span>
                                                    </div>

                                                    @if ($production['is_unassigned'] || ! $production['is_line_allowed'])
                                                        <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
                                                            @if ($production['is_unassigned'])
                                                                <span class="rounded-full bg-amber-100 px-2 py-1 font-medium text-amber-700">{{ __('Sans ligne') }}</span>
                                                            @endif
                                                            @if (! $production['is_line_allowed'])
                                                                <span class="rounded-full bg-amber-100 px-2 py-1 font-medium text-amber-700">{{ __('Ligne non autorisée') }}</span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($showTasks)
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($cell['tasks'] as $task)
                                                @php($taskBase = $task['is_capacity_consuming']
                                                    ? 'border-transparent bg-zinc-900 text-white cursor-grab active:cursor-grabbing'
                                                    : 'border-zinc-300 bg-white text-zinc-700 cursor-grab active:cursor-grabbing')
                                                @php($taskExtra = ($task['is_cancelled'] ? ' line-through opacity-60' : '') . ($task['is_finished'] ? ' opacity-50' : ''))

                                                <span
                                                    class="inline-flex select-none rounded-full border px-2 py-1 text-[11px] transition-opacity {{ $taskBase }}{{ $taskExtra }}"
                                                    draggable="true"
                                                    @dragstart="dragStart('task', {{ $task['id'] }}, true)"
                                                    @dragend="dragEnd()"
                                                    title="{{ $task['is_capacity_consuming'] ? __('Tâche consommatrice') : __('Tâche passive') }}"
                                                >
                                                    {{ $task['name'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
