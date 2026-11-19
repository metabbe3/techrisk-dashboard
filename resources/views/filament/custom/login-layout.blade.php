{{-- Passthrough layout: the login page wants a full-bleed split screen,
    not the boxed `fi-simple-main` card the default simple layout wraps
    every page in. Base layout still provides head/scripts/notifications. --}}
<x-filament-panels::layout.base :livewire="$livewire">
    {{ $slot }}
</x-filament-panels::layout.base>
