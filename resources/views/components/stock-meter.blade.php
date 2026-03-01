{{-- Stock Availability Visual Meter with Ingredient Min Stock Alert --}}
@php
$state = $getState();
$available = $state['available'] ?? 0;
$allocated = $state['allocated'] ?? 0;
$total = $state['total'] ?? 0;
$unit = $state['unit'] ?? 'kg';
$minStock = $state['min_stock'] ?? null;
$isBelowMin = $state['is_below_min'] ?? false;

$percentage = $total > 0 ? ($available / $total) * 100 : 0;
$allocatedPercentage = $total > 0 ? ($allocated / $total) * 100 : 0;

// Determine color based on min stock alert first, then percentage
if ($isBelowMin) {
    $color = 'rose';
    $statusText = 'Stock faible';
} elseif ($available <= 0) {
    $color = 'rose';
    $statusText = 'Épuisé';
} elseif ($percentage <= 20) {
    $color = 'amber';
    $statusText = 'Faible';
} elseif ($percentage <= 50) {
    $color = 'yellow';
    $statusText = 'Moyen';
} else {
    $color = 'emerald';
    $statusText = 'Bon';
}
@endphp

<div class="flex flex-col gap-1 min-w-[140px]">
    {{-- Available quantity with alert icon --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-1">
            @if ($isBelowMin)
                <x-heroicon-s-exclamation-triangle class="w-4 h-4 text-rose-500" />
            @endif
            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ number_format($available, 2) }} {{ $unit }}
            </span>
        </div>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            dispo
        </span>
    </div>
    
    {{-- Visual bar --}}
    <div class="h-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden flex">
        @if ($total > 0)
            {{-- Allocated portion (gray) --}}
            @if ($allocated > 0)
                <div 
                    class="h-full bg-gray-400 dark:bg-gray-500"
                    style="width: {{ $allocatedPercentage }}%"
                ></div>
            @endif
            {{-- Available portion (colored based on status) --}}
            <div 
                class="h-full bg-{{ $color }}-500"
                style="width: {{ $percentage }}%"
            ></div>
        @endif
    </div>
    
    {{-- Context line with min stock if applicable --}}
    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
        <span>Total: {{ number_format($total, 2) }}</span>
        @if ($isBelowMin && $minStock !== null)
            <span class="text-rose-500 font-medium">Min: {{ number_format($minStock, 2) }}</span>
        @elseif ($allocated > 0)
            <span>Alloué: {{ number_format($allocated, 2) }}</span>
        @endif
    </div>
</div>
