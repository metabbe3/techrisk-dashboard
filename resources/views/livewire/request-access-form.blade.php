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
                {{ $this->form }}

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50 cursor-not-allowed"
                    class="fi-btn w-full relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-primary fi-btn-color-primary bg-custom-600 text-white hover:bg-custom-500 dark:bg-custom-500 dark:hover:bg-custom-400 px-6 py-2.5 text-sm inline-grid shadow-sm"
                    style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
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
