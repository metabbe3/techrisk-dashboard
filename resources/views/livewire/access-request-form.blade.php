<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Dashboard Access - Tech Risk Dashboard</title>
    @livewireStyles
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
        <div class="w-full max-w-2xl">
            @if($submitted)
                <div class="rounded-lg bg-white p-8 shadow-md">
                    <div class="text-center">
                        <svg class="mx-auto h-16 w-16 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <h2 class="mt-6 text-2xl font-bold text-gray-900">Request Submitted</h2>
                        <p class="mt-4 text-gray-600">{{ $submittedMessage }}</p>
                        <div class="mt-8">
                            <a href="{{ url('/') }}" class="inline-flex items-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">
                                Return to Home
                            </a>
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-lg bg-white shadow-md">
                    <div class="px-8 py-6 border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-900">Request Dashboard Access</h2>
                        <p class="mt-2 text-sm text-gray-600">Fill out the form below to request access to the Tech Risk Dashboard.</p>
                    </div>

                    <form wire:submit.prevent="submit" class="p-8 space-y-6">
                        <!-- Name -->
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700">Full Name <span class="text-red-500">*</span></label>
                            <input
                                type="text"
                                wire:model="name"
                                id="name"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm @error('name') border-red-500 @enderror"
                                placeholder="John Doe"
                            >
                            @error('name')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700">Email Address <span class="text-red-500">*</span></label>
                            <input
                                type="email"
                                wire:model="email"
                                id="email"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm @error('email') border-red-500 @enderror"
                                placeholder="john.doe@example.com"
                            >
                            @error('email')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Password -->
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700">
                                Password <span class="text-gray-400">(leave blank if you already have an account)</span>
                            </label>
                            <input
                                type="password"
                                wire:model="password"
                                id="password"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm @error('password') border-red-500 @enderror"
                                placeholder="Min. 8 characters"
                            >
                            @error('password')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Duration -->
                        <div>
                            <label for="duration" class="block text-sm font-medium text-gray-700">Access Duration (Days) <span class="text-red-500">*</span></label>
                            <select
                                wire:model="requested_duration_days"
                                id="duration"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
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
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Requested Years -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Data Years Required <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                @foreach($this->availableYears as $year => $label)
                                    <label class="flex items-center space-x-2 p-3 border rounded-lg cursor-pointer hover:bg-gray-50 {{ in_array($year, $requested_years) ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300' }}">
                                        <input
                                            type="checkbox"
                                            wire:model="requested_years"
                                            value="{{ $year }}"
                                            class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                        <span class="text-sm font-medium">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('requested_years')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Reason -->
                        <div>
                            <label for="reason" class="block text-sm font-medium text-gray-700">Reason for Access <span class="text-red-500">*</span></label>
                            <textarea
                                wire:model="reason"
                                id="reason"
                                rows="4"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm @error('reason') border-red-500 @enderror"
                                placeholder="Please explain why you need access to the dashboard and what data you will be working with..."
                            ></textarea>
                            @error('reason')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Submit Button -->
                        <div class="flex items-center justify-end space-x-4">
                            <a href="{{ url('/') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-md border border-transparent bg-indigo-600 px-6 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700"
                            >
                                Submit Request
                            </button>
                        </div>
                    </form>
                </div>

                <div class="mt-6 text-center text-sm text-gray-500">
                    <p>Already have an account? <a href="{{ url('/admin/login') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Sign in here</a></p>
                </div>
            @endif
        </div>
    </div>
    @livewireScripts
</body>
</html>
