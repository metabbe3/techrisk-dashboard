<x-filament-panels::page>
    @if ($this->submitted)
        <div class="flex flex-col items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
            <div class="w-full max-w-md">
                <div class="rounded-xl bg-white dark:bg-gray-800 p-8 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                            <svg class="h-8 w-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h2 class="mt-6 text-2xl font-semibold text-gray-900 dark:text-gray-100">Request Submitted</h2>
                        <p class="mt-4 text-gray-600 dark:text-gray-400">Your access request has been submitted successfully. You will receive an email once your request is reviewed and approved.</p>
                        <div class="mt-8">
                            <a href="{{ url('/') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm transition-all duration-200 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800">
                                Return to Home
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="flex min-h-screen flex-col justify-center px-4 py-12 sm:px-6 lg:px-8 bg-gray-50 dark:bg-gray-900">
            <div class="sm:mx-auto sm:w-full sm:max-w-2xl">
                <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-8 py-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Request Dashboard Access</h2>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Fill out the form below to request access to the Tech Risk Dashboard.</p>
                    </div>

                    <form wire:submit.prevent="submit" class="p-8">
                        {{ $this->form }}

                        <div class="flex items-center justify-end gap-3 pt-6">
                            <a href="{{ url('/') }}" class="text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">
                                Cancel
                            </a>
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
            </div>
        </div>
    @endif
</x-filament-panels::page>
