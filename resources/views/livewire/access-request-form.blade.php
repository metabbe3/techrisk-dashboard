<div class="flex min-h-screen flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8 bg-gray-50 dark:bg-gray-900">
    <div class="w-full max-w-2xl">
        @if($submitted)
            <div class="rounded-xl bg-white dark:bg-gray-800 p-8 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="text-center">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                        <svg class="h-8 w-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h2 class="mt-6 text-2xl font-semibold text-gray-900 dark:text-gray-100">Request Submitted</h2>
                    <p class="mt-4 text-gray-600 dark:text-gray-400">{{ $submittedMessage }}</p>
                    <div class="mt-8">
                        <a href="{{ url('/') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800">
                            Return to Home
                        </a>
                    </div>
                </div>
            </div>
        @else
            <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="px-8 py-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Request Dashboard Access</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Fill out the form below to request access to the Tech Risk Dashboard.</p>
                </div>

                <form wire:submit.prevent="submit" class="p-8 space-y-6">
                    <!-- Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Full Name <span class="text-red-500">*</span></label>
                        <input
                            type="text"
                            wire:model="name"
                            id="name"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm @error('name') border-red-500 @enderror py-2.5 px-3"
                            placeholder="John Doe"
                        >
                        @error('name')
                            <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email Address <span class="text-red-500">*</span></label>
                        <input
                            type="email"
                            wire:model="email"
                            id="email"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm @error('email') border-red-500 @enderror py-2.5 px-3"
                            placeholder="john.doe@example.com"
                        >
                        @error('email')
                            <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Password <span class="text-gray-400 dark:text-gray-500">(leave blank if you already have an account)</span>
                        </label>
                        <input
                            type="password"
                            wire:model="password"
                            id="password"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm @error('password') border-red-500 @enderror py-2.5 px-3"
                            placeholder="Min. 8 characters"
                        >
                        @error('password')
                            <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Duration -->
                    <div>
                        <label for="duration" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Access Duration <span class="text-red-500">*</span></label>
                        <select
                            wire:model="requested_duration_days"
                            id="duration"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm py-2.5 px-3"
                        >
                            <option value="7">7 days</option>
                            <option value="14">14 days</option>
                            <option value="30">30 days (1 month)</option>
                            <option value="60">60 days (2 months)</option>
                            <option value="90">90 days (3 months)</option>
                            <option value="180">180 days (6 months)</option>
                            <option value="365">365 days (1 year)</option>
                        </select>
                        @error('requested_duration_days')
                            <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Requested Years -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Data Years Required <span class="text-red-500">*</span>
                        </label>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @foreach($this->availableYears as $year => $label)
                                <label class="flex items-center space-x-3 p-3.5 border-2 rounded-xl cursor-pointer transition-all duration-200 {{ in_array($year, $requested_years) ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/50' }}">
                                    <input
                                        type="checkbox"
                                        wire:model="requested_years"
                                        value="{{ $year }}"
                                        class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600"
                                    >
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('requested_years')
                            <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Reason -->
                    <div>
                        <label for="reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reason for Access <span class="text-red-500">*</span></label>
                        <textarea
                            wire:model="reason"
                            id="reason"
                            rows="4"
                            class="mt-1.5 block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm @error('reason') border-red-500 @enderror py-2.5 px-3"
                            placeholder="Please explain why you need access to the dashboard and what data you will be working with..."
                        ></textarea>
                        @error('reason')
                            <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Submit Button -->
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <a href="{{ url('/') }}" class="text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">Cancel</a>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 cursor-not-allowed"
                            class="inline-flex items-center rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm transition-all duration-200 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                        >
                            <span wire:loading.remove>Submit Request</span>
                            <span wire:loading class="hidden">Submitting...</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="mt-6 text-center text-sm text-gray-600 dark:text-gray-400">
                <p>Already have an account? <a href="{{ url('/admin/login') }}" class="font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition-colors">Sign in here</a></p>
            </div>
        @endif
    </div>
</div>
