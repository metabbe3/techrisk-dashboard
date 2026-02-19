@php
    use Filament\Support\Facades\FilamentView;
@endphp

<x-filament-panels::page>
    @if ($this->getViewData()['showToken'])
        <div class="space-y-6">
            <x-slot name="heading">
                API Token Created Successfully
            </x-slot>

            <div class="bg-success-50 dark:bg-success-950 border border-success-200 dark:border-success-800 rounded-lg p-6">
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <x-heroicon-o-check-circle class="w-6 h-6 text-success-600 dark:text-success-400" />
                        <h3 class="text-lg font-semibold text-success-900 dark:text-success-100">
                            Token Created - Save It Now
                        </h3>
                    </div>

                    <p class="text-sm text-success-700 dark:text-success-300">
                        This token will only be shown once. Please save it securely now. You won't be able to see it again.
                    </p>

                    <div class="bg-white dark:bg-gray-900 rounded-lg p-4 border border-success-200 dark:border-success-800">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">
                            Your API Token:
                        </label>
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                value="{{ $this->getViewData()['plainTextToken'] }}"
                                readonly
                                class="flex-1 font-mono text-sm bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded px-3 py-2"
                                id="api-token-display"
                            />
                            <button
                                type="button"
                                x-data="{
                                    copy() {
                                        navigator.clipboard.writeText(document.getElementById('api-token-display').value);
                                        this.$el.textContent = 'Copied!';
                                        setTimeout(() => this.$el.textContent = 'Copy', 2000);
                                    }
                                }"
                                @click="copy"
                                class="px-4 py-2 bg-success-600 hover:bg-success-700 text-white rounded-lg text-sm font-medium transition-colors"
                            >
                                Copy
                            </button>
                        </div>
                    </div>

                    <div class="bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                        <h4 class="font-semibold text-amber-900 dark:text-amber-100 flex items-center gap-2 mb-2">
                            <x-heroicon-o-exclamation-triangle class="w-5 h-5" />
                            Security Notice
                        </h4>
                        <ul class="text-sm text-amber-800 dark:text-amber-200 space-y-1">
                            <li>• Store this token securely (e.g., password manager, environment variables)</li>
                            <li>• Never share this token via email, chat, or version control</li>
                            <li>• This token will expire after 30 days of inactivity</li>
                            <li>• Revoke this token immediately if it's compromised</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="bg-warning-50 dark:bg-warning-950 border border-warning-200 dark:border-warning-800 rounded-lg p-6">
            <div class="flex items-center gap-3">
                <x-heroicon-o-lock-closed class="w-6 h-6 text-warning-600 dark:text-warning-400" />
                <div>
                    <h3 class="text-lg font-semibold text-warning-900 dark:text-warning-100">
                        Token Hidden
                    </h3>
                    <p class="text-sm text-warning-700 dark:text-warning-300">
                        This token was already shown during creation. For security reasons, it cannot be displayed again.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <div class="mt-8">
        {{ $this->table }}
    </div>

    @script
        <script>
            function copyToClipboard(text) {
                navigator.clipboard.writeText(text);
            }

            window.addEventListener('copy-to-clipboard', (event) => {
                copyToClipboard(event.detail.text);
            });
        </script>
    @endscript
</x-filament-panels::page>
