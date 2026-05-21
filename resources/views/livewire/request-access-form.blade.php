<div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-indigo-50 via-white to-violet-50 dark:from-gray-900 dark:via-gray-900 dark:to-gray-800 px-4 py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md">
        {{-- Header --}}
        <div class="text-center mb-8">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 shadow-lg mb-4">
                <svg class="h-7 w-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Request Access</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Technical Risk Dashboard</p>
        </div>

        @if($submitted)
            {{-- Success State --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 p-8 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700 text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30 mb-4">
                    <svg class="h-8 w-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">Request Submitted</h2>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                    Your access request has been submitted successfully. You will receive an email once your request is reviewed and approved.
                </p>
                <a href="{{ url('/') }}" class="mt-6 inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:shadow-lg hover:from-indigo-700 hover:to-violet-700 transition-all">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Return to Home
                </a>
            </div>
        @else
            {{-- Form Card --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 p-7 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700">
                <form wire:submit="submit" class="space-y-4">
                    {{-- Full Name --}}
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Name <span class="text-red-500">*</span></label>
                        <input
                            type="text"
                            wire:model="data.name"
                            id="name"
                            class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2.5 px-4 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition-all sm:text-sm"
                            placeholder="John Doe"
                        />
                        @error('data.name')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Email --}}
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email Address <span class="text-red-500">*</span></label>
                        <input
                            type="email"
                            wire:model="data.email"
                            id="email"
                            class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2.5 px-4 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition-all sm:text-sm"
                            placeholder="john.doe@example.com"
                        />
                        @error('data.email')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Password --}}
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Password
                            <span class="font-normal text-gray-400 dark:text-gray-500">(leave blank if you already have an account)</span>
                        </label>
                        <input
                            type="password"
                            wire:model="data.password"
                            id="password"
                            class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2.5 px-4 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition-all sm:text-sm"
                            placeholder="Min. 8 characters"
                        />
                        @error('data.password')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Access Duration --}}
                    <div>
                        <label for="duration" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Access Duration <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <select
                                wire:model="data.requested_duration_days"
                                id="duration"
                                class="block w-full appearance-none rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2.5 pl-4 pr-10 text-gray-900 dark:text-gray-100 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition-all sm:text-sm"
                            >
                                <option value="7">7 days</option>
                                <option value="14">14 days</option>
                                <option value="30">30 days (1 month)</option>
                                <option value="60">60 days (2 months)</option>
                                <option value="90">90 days (3 months)</option>
                                <option value="180">180 days (6 months)</option>
                                <option value="365">365 days (1 year)</option>
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                <svg class="h-5 w-5 text-gray-400 dark:text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                        @error('data.requested_duration_days')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Data Years Required --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Data Years Required <span class="text-red-500">*</span>
                        </label>
                        <div class="grid grid-cols-4 gap-2">
                            @foreach($this->availableYears as $year => $label)
                                <label class="relative flex cursor-pointer items-center justify-center rounded-xl py-2.5 text-center text-sm font-medium transition-all {{ in_array($year, $requested_years) ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' }}">
                                    <input
                                        type="checkbox"
                                        wire:model="data.requested_years"
                                        value="{{ $year }}"
                                        class="sr-only"
                                    />
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('data.requested_years')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Reason --}}
                    <div>
                        <label for="reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason for Access <span class="text-red-500">*</span></label>
                        <textarea
                            wire:model="data.reason"
                            id="reason"
                            rows="3"
                            class="block w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2.5 px-4 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none transition-all sm:text-sm resize-none"
                            placeholder="Please explain why you need access to the dashboard..."
                        ></textarea>
                        @error('data.reason')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Submit Button --}}
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 cursor-not-allowed"
                        class="w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-3 text-sm font-semibold text-white shadow-md hover:from-indigo-700 hover:to-violet-700 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-all"
                    >
                        <span wire:loading.remove>Submit Request</span>
                        <span wire:loading class="hidden">Submitting...</span>
                    </button>
                </form>
            </div>

            {{-- Sign in link --}}
            <div class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                Already have an account?
                <a href="{{ url('/admin/login') }}" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500 dark:hover:text-indigo-300 transition-colors">
                    Sign in
                </a>
            </div>
        @endif
    </div>
</div>
