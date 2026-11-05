@if (session('microsoft_error'))
    <p class="mb-4 text-sm font-medium text-danger-600 dark:text-danger-400">
        {{ session('microsoft_error') }}
    </p>
@endif

<div class="mt-4">
    <x-filament::button tag="a" href="{{ route('microsoft.redirect') }}" color="gray" class="w-full">
        Sign in with Microsoft
    </x-filament::button>
</div>
