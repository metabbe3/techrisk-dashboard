{{-- ProfileSetting's view (owner 2026-11-20, revisi 3): the dedicated
     change-password page reached from the profile dropdown's "Setting" item.
     Field block matches the profile dropdown's nav order conventions. --}}
<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>
</x-filament-panels::page>
