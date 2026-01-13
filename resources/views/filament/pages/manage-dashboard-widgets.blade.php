<x-filament-panels::page>
    <div class="fi-section-header fi-section-header-has-description mb-6">
        <div class="fi-section-header-heading">
            <h2 class="fi-section-header-title">
                Customize Your Dashboard
            </h2>
            <span class="fi-section-header-description">
                Toggle widgets on/off and drag to reorder them
            </span>
        </div>
    </div>

    <div x-data="{
        widgets: @json($availableWidgets),
        enabled: @json($enabledWidgets),
        getOrder() {
            return Object.entries(this.enabled)
                .filter(([_, v]) => v.enabled)
                .sort((a, b) => a[1].sort_order - b[1].sort_order)
                .map(([k, _]) => k);
        },
        toggle(widgetClass) {
            @this.toggleWidget(widgetClass);
        },
        saveOrder() {
            const newOrder = Array.from(
                document.querySelectorAll('[data-widget-item]')
            ).map(el => el.dataset.widgetClass);
            @this.saveOrder(newOrder);
        },
        reset() {
            if (confirm('Are you sure you want to reset to default widgets?')) {
                @this.resetToDefaults();
            }
        }
    }" class="space-y-6">

        <!-- Actions -->
        <div class="flex gap-3">
            <button
                type="button"
                @click="saveOrder()"
                class="fi-btn fi-btn-primary inline-flex items-center justify-center gap-1 outline-none focus:ring-2 focus:ring-offset-2 transition duration-75 ease-in-out hover:bg-primary-600 dark:hover:bg-primary-500 hover:text-white dark:hover:text-white ring-primary-600 dark:ring-primary-500 bg-primary-600 text-white dark:bg-primary-500 rounded-lg fi-color-custom fi-color-enabled fi-size-md fi-has-icon fi-has-left-icon"
            >
                <svg class="fi-btn-icon w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                </svg>
                <span>Save Order</span>
            </button>

            <button
                type="button"
                @click="reset()"
                class="fi-btn fi-btn-gray inline-flex items-center justify-center gap-1 outline-none focus:ring-2 focus:ring-offset-2 transition duration-75 ease-in-out hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 ring-gray-600 dark:ring-gray-500 bg-white dark:bg-gray-800 rounded-lg fi-color-custom fi-color-enabled fi-size-md fi-has-icon fi-has-left-icon"
            >
                <svg class="fi-btn-icon w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 010 1.5H3.989a.75.75 0 01-.75-.75V4.25a.75.75 0 011.5 0v2.433l.311-.31a5.5 5.5 0 0110.262 5.051z" clip-rule="evenodd" />
                </svg>
                <span>Reset to Defaults</span>
            </button>
        </div>

        <!-- Widget List (Draggable) -->
        <div
            class="space-y-2"
            x-sortable
            @end="saveOrder()"
        >
            <template x-for="(widget, key) in widgets" :key="key">
                <div
                    x-show="enabled[widget.class]?.enabled ?? true"
                    data-widget-item
                    :data-widget-class="widget.class"
                    class="widget-item flex items-center gap-4 p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 cursor-move hover:border-primary-500 dark:hover:border-primary-500 transition-colors"
                    draggable="true"
                >
                    <!-- Drag Handle -->
                    <div class="cursor-move text-gray-400 hover:text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 10.5a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75a.75.75 0 01-.75-.75zM8 4.75a.75.75 0 01.75.75h7.5a.75.75 0 010-1.5h-7.5a.75.75 0 01-.75.75zm0 10.5a.75.75 0 01.75.75h7.5a.75.75 0 010-1.5h-7.5a.75.75 0 01-.75.75z" clip-rule="evenodd" />
                        </svg>
                    </div>

                    <!-- Widget Icon -->
                    <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500 dark:text-gray-400" x-cloak :class="widget.icon.replace('heroicon-', 'heroicon ')" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path x-show="widget.icon.includes('chart-bar')" stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            <path x-show="widget.icon.includes('check-circle')" stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            <path x-show="widget.icon.includes('calendar')" stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            <path x-show="widget.icon.includes('exclamation-triangle')" stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            <path x-show="widget.icon.includes('tag')" stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                            <path x-show="widget.icon.includes('user')" stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            <path x-show="widget.icon.includes('currency-dollar')" stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            <path x-show="widget.icon.includes('trending-up')" stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            <path x-show="widget.icon.includes('clock')" stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            <path x-show="widget.icon.includes('inbox')" stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                        </svg>
                    </div>

                    <!-- Widget Info -->
                    <div class="flex-1">
                        <h3 class="font-medium text-gray-900 dark:text-gray-100" x-text="widget.name"></h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="widget.description"></p>
                    </div>

                    <!-- Toggle -->
                    <button
                        @click="toggle(widget.class)"
                        type="button"
                        role="switch"
                        :aria-checked="enabled[widget.class]?.enabled ?? true"
                        class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-600 focus:ring-offset-2"
                        :class="(enabled[widget.class]?.enabled ?? true) ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-700'"
                    >
                        <span
                            class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transform ring-0 transition duration-200 ease-in-out"
                            :class="(enabled[widget.class]?.enabled ?? true) ? 'translate-x-5' : 'translate-x-0'"
                            aria-hidden="true"
                        ></span>
                    </button>
                </div>
            </template>
        </div>

        <!-- Help Text -->
        <div class="bg-blue-50 dark:bg-gray-800 border border-blue-200 dark:border-gray-700 rounded-lg p-4">
            <div class="flex gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div class="text-sm text-blue-800 dark:text-blue-200">
                    <p class="font-medium mb-1">How to use:</p>
                    <ul class="list-disc list-inside space-y-1 text-blue-700 dark:text-blue-300">
                        <li><strong>Drag and drop</strong> widgets to reorder them</li>
                        <li><strong>Toggle the switch</strong> to show/hide widgets</li>
                        <li>Changes are saved automatically when you drag</li>
                        <li>Click "Reset to Defaults" to restore original layout</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.directive('sortable', (el) => {
                let draggedItem = null;
                let placeholder = null;

                el.addEventListener('dragstart', (e) => {
                    draggedItem = e.target;
                    e.target.style.opacity = '0.5';
                });

                el.addEventListener('dragend', (e) => {
                    e.target.style.opacity = '1';
                    draggedItem = null;
                    if (placeholder) {
                        placeholder.remove();
                        placeholder = null;
                    }
                });

                el.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    const afterElement = getDragAfterElement(el, e.clientY);
                    if (afterElement == null) {
                        el.appendChild(draggedItem);
                    } else {
                        el.insertBefore(draggedItem, afterElement);
                    }
                });

                function getDragAfterElement(container, y) {
                    const draggableElements = [...container.querySelectorAll('[data-widget-item]:not(.dragging)')];

                    return draggableElements.reduce((closest, child) => {
                        const box = child.getBoundingClientRect();
                        const offset = y - box.top - box.height / 2;
                        if (offset < 0 && offset > closest.offset) {
                            return { offset: offset, element: child };
                        } else {
                            return closest;
                        }
                    }, { offset: Number.NEGATIVE_INFINITY }).element;
                }
            });
        });
    </script>
</x-filament-panels::page>
