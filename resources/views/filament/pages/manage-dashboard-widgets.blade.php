<x-filament-panels::page>
    <x-slot name="header">
        <div class="fi-custom-widget-header" style="background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%); padding: 2rem; border-radius: 1rem; margin-bottom: 1.5rem; color: white; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h1 style="font-size: 1.875rem; font-weight: 700; margin: 0 0 0.5rem 0;">
                        Customize Your Dashboard
                    </h1>
                    <p style="margin: 0; opacity: 0.9; font-size: 1.125rem;">
                        Drag rows to reorder and click toggles to enable/disable widgets
                    </p>
                </div>
                <div style="display: flex; align-items: center; gap: 1rem; background: rgba(255,255,255,0.2); padding: 0.75rem 1.5rem; border-radius: 0.75rem; backdrop-filter: blur(10px);">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="opacity: 0.9;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span style="font-size: 0.875rem; opacity: 0.9;">Changes are saved automatically</span>
                </div>
            </div>
        </div>
    </x-slot>

    {{ $this->table }}

    <style>
        [x-cloak] {
            display: none !important;
        }

        /* Custom header styling */
        .fi-custom-widget-header {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Drag handle visibility */
        .fi-tables-record {
            cursor: grab;
        }

        .fi-tables-record:active {
            cursor: grabbing;
        }

        /* Enhance toggle visibility */
        .fi-toggle-column-toggle {
            transition: all 0.2s ease-in-out;
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .fi-custom-widget-header {
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            }
        }

        .dark .fi-custom-widget-header {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }
    </style>
</x-filament-panels::page>
