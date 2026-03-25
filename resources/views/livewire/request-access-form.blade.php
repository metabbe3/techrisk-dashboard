<div class="flex min-h-screen flex-col items-center justify-center bg-gray-50 dark:bg-gray-950 py-12 px-4 sm:px-6 lg:px-8">
    <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @if($submitted)
            <div class="text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                    <svg class="h-8 w-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <h2 class="mt-6 text-2xl font-bold text-gray-900 dark:text-white">Request Submitted</h2>
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">Your access request has been submitted successfully. You will receive an email once your request is reviewed and approved.</p>
                <div class="mt-8">
                    <a href="{{ url('/') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800">
                        Return to Home
                    </a>
                </div>
            </div>
        @else
            <div class="text-center mb-8">
                <h2 class="text-lg font-medium text-gray-950 dark:text-white">
                    Technical Risk Dashboard
                </h2>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                    Request Access
                </h1>
            </div>

            <form wire:submit.prevent="submit" class="space-y-6">
                <!-- Name -->
                <div>
                    <label for="name" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Full Name <span class="text-red-500">*</span></label>
                    <input
                        type="text"
                        wire:model.live="name"
                        id="name"
                        @class([
                            'mt-2 block w-full rounded-md border-0 py-2.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-white/5 dark:text-white sm:text-sm sm:leading-6',
                            'ring-red-600 focus:ring-red-600' => $errors->has('name')
                        ])
                        placeholder="John Doe"
                    />
                    @error('name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Email Address <span class="text-red-500">*</span></label>
                    <input
                        type="email"
                        wire:model.live="email"
                        id="email"
                        @class([
                            'mt-2 block w-full rounded-md border-0 py-2.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-white/5 dark:text-white sm:text-sm sm:leading-6',
                            'ring-red-600 focus:ring-red-600' => $errors->has('email')
                        ])
                        placeholder="john.doe@example.com"
                    />
                    @error('email')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Password -->
                <div>
                    <label for="password" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">
                        Password
                        <span class="font-normal text-gray-500">(leave blank if you already have an account)</span>
                    </label>
                    <input
                        type="password"
                        wire:model.live="password"
                        id="password"
                        class="mt-2 block w-full rounded-md border-0 py-2.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-white/5 dark:text-white sm:text-sm sm:leading-6"
                        placeholder="Min. 8 characters"
                    />
                    @error('password')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Duration -->
                <div>
                    <label for="duration" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Access Duration <span class="text-red-500">*</span></label>
                    <select
                        wire:model.live="requested_duration_days"
                        id="duration"
                        class="mt-2 block w-full rounded-md border-0 py-2.5 pl-3 pr-10 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-white/5 dark:text-white sm:text-sm sm:leading-6"
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
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Requested Years -->
                <div>
                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-white mb-3">
                        Data Years Required <span class="text-red-500">*</span>
                    </label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        @foreach($this->availableYears as $year => $label)
                            <label class="flex items-center space-x-3 p-3.5 border-2 rounded-xl cursor-pointer transition-all duration-200 {{ in_array($year, $requested_years) ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/50' }}">
                                <input
                                    type="checkbox"
                                    wire:model.live="requested_years"
                                    value="{{ $year }}"
                                    class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600"
                                />
                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('requested_years')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Reason -->
                <div>
                    <label for="reason" class="block text-sm font-medium leading-6 text-gray-900 dark:text-white">Reason for Access <span class="text-red-500">*</span></label>
                    <textarea
                        wire:model.live="reason"
                        id="reason"
                        rows="4"
                        @class([
                            'mt-2 block w-full rounded-md border-0 py-2.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-white/5 dark:text-white sm:text-sm sm:leading-6',
                            'ring-red-600 focus:ring-red-600' => $errors->has('reason')
                        ])
                        placeholder="Please explain why you need access to the dashboard and what data you will be working with..."
                    ></textarea>
                    @error('reason')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50 cursor-not-allowed"
                    class="w-full relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg bg-blue-600 px-6 py-2.5 text-sm text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                >
                    <span wire:loading.remove>Submit Request</span>
                    <span wire:loading class="hidden">Submitting...</span>
                </button>
            </form>

            <div class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                Already have an account?
                <a href="{{ url('/admin/login') }}" class="font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors">
                    Sign in here
                </a>
            </div>
        @endif
    </div>
</div>
