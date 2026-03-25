<div class="flex min-h-screen flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8 bg-gray-950 dark:bg-gray-950">
    <div class="w-full max-w-3xl">
        @if($submitted)
            <div class="rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
                <div class="text-center p-8">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                        <svg class="h-8 w-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h2 class="mt-6 text-2xl font-semibold text-gray-900 dark:text-white">Request Submitted</h2>
                    <p class="mt-4 text-gray-600 dark:text-gray-400">Your access request has been submitted successfully. You will receive an email once your request is reviewed and approved.</p>
                    <div class="mt-8">
                        <a href="{{ url('/') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800">
                            Return to Home
                        </a>
                    </div>
                </div>
            </div>
        @else
            <div class="rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
                <div class="px-8 py-6 border-b border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/5">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Request Dashboard Access</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Fill out the form below to request access to the Tech Risk Dashboard.</p>
                </div>

                <form wire:submit.prevent="submit" class="p-8 space-y-6">
                    {{ $this->form }}

                    <div class="flex items-center justify-end gap-4 mt-8 pt-6 border-t border-gray-200 dark:border-white/10">
                        <a href="{{ url('/') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white transition-colors">
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

            <div class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                <p>Already have an account? <a href="{{ url('/admin/login') }}" class="font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors">Sign in here</a></p>
            </div>
        @endif
    </div>
</div>
