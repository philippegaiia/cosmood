<x-filament::form wire:model="save" />
    <div class="flex justify-end gap-3 pt-6">
        <flux:button variant="ghost" wire:click="cancel">
            {{ __('Annuler') }}
        </flux:button>
        <flux:button variant="primary" wire:click="save">
            {{ __('Enregistrer') }}
        </flux:button>
    </div>
