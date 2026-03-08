<div class="flex w-full flex-col gap-0.5 text-[11px] leading-tight">
    <span class="truncate font-semibold" x-text="event.extendedProps.productName ?? event.title"></span>
    <span class="truncate opacity-90" x-text="event.extendedProps.lotLabel"></span>
    <template x-if="event.extendedProps.eventType === 'task' && event.extendedProps.taskName">
        <span class="truncate text-[10px] opacity-80" x-text="event.extendedProps.taskName"></span>
    </template>
</div>
