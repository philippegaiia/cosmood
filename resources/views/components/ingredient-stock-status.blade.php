{{-- Ingredient Stock Status Component --}}
@php
$state = $getState();
$available = $state['available'] ?? 0;
$total = $state['total'] ?? 0;
$allocated = $state['allocated'] ?? 0;
$status = $state['status'];
$unit = $state['unit'] ?? 'kg';
$percentage = $state['percentage'] ?? 0;

// Determine color based on status
$colorClass = match($status->value) {
    'disponible' => 'bg-emerald-500',
    'faible' => 'bg-amber-500',
    'rupture' => 'bg-rose-500',
    default => 'bg-gray-500',
};

$badgeColor = match($status->value) {
    'disponible' => 'success',
    'faible' => 'warning',
    'rupture' => 'danger',
    default => 'gray',
};
@endphp

<div class="flex flex-col gap-2 min-w-[160px]">
    {{-- Status badge --}}
    <div class="flex items-center gap-2">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
            {{ number_format($available, 2) }} {{ $unit }}
        </span>
        <span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-w-[theme(spacing.6)] py-1 fi-color-{{ $badgeColor }}">
            {{ $status->getLabel() }}
        </span>
    </div>
    
    {{-- Visual bar --}}
    <div class="h-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden flex">
        @if ($total > 0)
            {{-- Allocated portion (gray) --}}
            @if ($allocated > 0)
                <div 
                    class="h-full bg-gray-400 dark:bg-gray-500"
                    style="width: {{ ($allocated / $total) * 100 }}%"
                ></div>
            @endif
            {{-- Available portion (colored based on status) --}}
            <div 
                class="h-full {{ $colorClass }}"
                style="width: {{ $percentage }}%"
            ></div>
        @endif
    </div>
    
    {{-- Context line --}}
    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
        <span>Total: {{ number_format($total, 2) }}</span>
        @if ($allocated > 0)
            <span>Alloué: {{ number_format($allocated, 2) }}</span>
        @endif
    </div>
</div>
