<div class="grid min-h-screen lg:grid-cols-2">
    {{-- Brand panel — desktop only --}}
    <div class="hidden flex-col justify-between bg-indigo-600 px-12 py-10 text-white lg:flex dark:bg-indigo-700">
        <div class="text-lg font-bold tracking-tight">
            {{ filament()->getBrandName() }}
        </div>

        <div class="max-w-md">
            <h2 class="text-4xl font-bold leading-tight">
                Technical risk, one place.
            </h2>
            <p class="mt-4 text-indigo-200">
                Log incidents, track action items and keep the board informed —
                from a single portal.
            </p>
        </div>

        <p class="text-sm text-indigo-200">
            Internal tooling · Authorized access only
        </p>
    </div>

    {{-- Sign-in panel --}}
    <div class="flex flex-col items-center justify-center bg-white px-6 py-12 dark:bg-gray-950 sm:px-12">
        <div class="w-full max-w-sm">
            <h1 class="text-2xl font-bold text-gray-950 dark:text-white">
                Welcome back
            </h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Sign in with your DANA Microsoft account.
            </p>

            @if (session('microsoft_error'))
                <div class="mt-4 rounded-lg bg-danger-50 px-4 py-3 text-sm font-medium text-danger-600 dark:bg-danger-500/10 dark:text-danger-400">
                    {{ session('microsoft_error') }}
                </div>
            @endif

            <a
                href="{{ route('microsoft.redirect') }}"
                class="mt-6 flex h-10 w-full items-center justify-center gap-3 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-950"
            >
                <svg viewBox="0 0 21 21" class="h-4 w-4" aria-hidden="true">
                    <rect x="1" y="1" width="9" height="9" fill="#f25022" />
                    <rect x="11" y="1" width="9" height="9" fill="#7fba00" />
                    <rect x="1" y="11" width="9" height="9" fill="#00a4ef" />
                    <rect x="11" y="11" width="9" height="9" fill="#ffb900" />
                </svg>
                Sign in with Microsoft
            </a>

            {{-- Break-glass: password login is admin-only, enforced in Login::authenticate() --}}
            <div x-data="{ open: false }" class="mt-8">
                <button
                    type="button"
                    x-on:click="open = ! open"
                    class="text-xs font-medium text-gray-500 underline underline-offset-2 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                >
                    Admin password login
                </button>

                <div x-cloak x-show="open" class="mt-4">
                    <x-filament-panels::form wire:submit="authenticate">
                        {{ $this->form }}

                        <x-filament-panels::form.actions
                            :actions="$this->getCachedFormActions()"
                            :full-width="$this->hasFullWidthFormActions()"
                        />
                    </x-filament-panels::form>
                </div>
            </div>
        </div>
    </div>
</div>
