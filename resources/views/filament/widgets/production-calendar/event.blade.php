<div class="flex w-full flex-col gap-1 text-[11px] leading-tight">
    <div class="flex items-start justify-between gap-1">
        <span class="truncate font-semibold" x-text="event.extendedProps.productName ?? event.title"></span>
        <span
            class="shrink-0 rounded-full bg-white/20 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide"
            x-text="event.extendedProps.lineBadge"
        ></span>
    </div>

    <span class="truncate opacity-90" x-text="event.extendedProps.lotLabel"></span>

    <div class="flex flex-wrap gap-1 text-[10px] opacity-85">
        <span class="rounded-full bg-white/15 px-1.5 py-0.5" x-text="event.extendedProps.quantityLabel"></span>

        <template x-if="event.extendedProps.unitsLabel">
            <span class="rounded-full bg-white/15 px-1.5 py-0.5" x-text="event.extendedProps.unitsLabel"></span>
        </template>

        <template x-if="event.extendedProps.waveLabel">
            <span class="rounded-full bg-white/15 px-1.5 py-0.5" x-text="event.extendedProps.waveLabel"></span>
        </template>
    </div>
</div>
