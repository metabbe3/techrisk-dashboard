<x-filament-panels::page>
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
@endpush

<div x-data="aiChat()" x-init="init()" class="ai-chat-container">
    {{-- Sidebar --}}
    <div class="ai-chat-sidebar" :class="{ 'ai-chat-sidebar-open': showSidebar }">
        <div class="ai-chat-sidebar-header">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Conversations</h3>
            <button @click="newConversation()" class="ai-chat-new-btn" title="New Chat">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
            </button>
        </div>
        <div class="ai-chat-sidebar-search">
            <input type="text" x-model="searchQuery" @input.debounce.300ms="searchConversations()" placeholder="Search conversations..." class="ai-chat-search-input" />
        </div>
        <div class="ai-chat-sidebar-list">
            {{-- Pinned conversations --}}
            <template x-if="!searchQuery && conversations.filter(c => c.pinned).length > 0">
                <div>
                    <div class="px-3 py-1 text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Pinned</div>
                    <template x-for="conv in conversations.filter(c => c.pinned)" :key="'p-'+conv.id">
                        <div class="relative">
                            <div @click="selectConversation(conv.id)"
                                 class="ai-chat-sidebar-item"
                                 :class="{ 'active': activeConversationId === conv.id }">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium truncate">
                                        <span class="text-amber-500 mr-1">&#128204;</span>
                                        <template x-if="editingTitleId === conv.id">
                                            <input :id="'title-input-'+conv.id" x-model="editingTitleValue"
                                                   @keydown.enter.prevent="saveTitle(conv)"
                                                   @keydown.escape.prevent="cancelEditTitle()"
                                                   @blur="saveTitle(conv)"
                                                   @click.stop
                                                   class="ai-chat-title-input" maxlength="80" />
                                        </template>
                                        <template x-if="editingTitleId !== conv.id">
                                            <span x-text="conv.title || 'New Chat'"></span>
                                        </template>
                                    </p>
                                    <div class="flex items-center gap-1 mt-0.5">
                                        <p class="text-xs text-gray-400 dark:text-gray-500 truncate" x-text="conv.last_message"></p>
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        <template x-for="tag in (conv.tags || [])" :key="tag">
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-500" x-text="tag"></span>
                                        </template>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button @click.stop="startEditTitle(conv)" class="ai-chat-edit-btn" title="Rename">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                    </button>
                                    <button @click.stop="togglePin(conv)" class="text-amber-500 dark:text-amber-400 hover:text-amber-600 dark:hover:text-amber-300" title="Unpin">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/></svg>
                                    </button>
                                    <button @click.stop="deleteConversation(conv.id)" class="ai-chat-delete-btn" title="Delete">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
            {{-- Regular conversations (grouped by date) --}}
            <template x-for="group in groupedConversations" :key="group.label">
                <div>
                    <button @click="collapsedGroups[group.label] = !collapsedGroups[group.label]" class="ai-chat-group-header">
                        <svg class="w-2.5 h-2.5 transition-transform" :class="{ '-rotate-90': collapsedGroups[group.label] }" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                        <span x-text="group.label + ' (' + group.items.length + ')'"></span>
                    </button>
                    <div x-show="!collapsedGroups[group.label]" x-transition>
                        <template x-for="conv in group.items" :key="conv.id">
                            <div class="relative">
                                <div @click="selectConversation(conv.id)"
                                     class="ai-chat-sidebar-item"
                                     :class="{ 'active': activeConversationId === conv.id }">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium truncate">
                                            <template x-if="editingTitleId === conv.id">
                                                <input :id="'title-input-'+conv.id" x-model="editingTitleValue"
                                                       @keydown.enter.prevent="saveTitle(conv)"
                                                       @keydown.escape.prevent="cancelEditTitle()"
                                                       @blur="saveTitle(conv)"
                                                       @click.stop
                                                       class="ai-chat-title-input" maxlength="80" />
                                            </template>
                                            <template x-if="editingTitleId !== conv.id">
                                                <span x-text="conv.title || 'New Chat'"></span>
                                            </template>
                                        </p>
                                        <div class="flex items-center gap-1 mt-0.5">
                                            <p class="text-xs text-gray-400 dark:text-gray-500 truncate" x-text="conv.last_message"></p>
                                        </div>
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            <template x-for="tag in (conv.tags || []).slice(0, 3)" :key="tag">
                                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-500" x-text="tag"></span>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <button @click.stop="startEditTitle(conv)" class="ai-chat-edit-btn" title="Rename">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                        </button>
                                        <button @click.stop="togglePin(conv)" class="hover:text-amber-500" :class="conv.pinned ? 'text-amber-500' : 'text-gray-300'" title="Pin">
                                            <svg class="w-3 h-3" :fill="conv.pinned ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/></svg>
                                        </button>
                                        <button @click.stop="deleteConversation(conv.id)" class="ai-chat-delete-btn" title="Delete">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
            <div x-show="conversations.length === 0" class="p-4 text-center text-xs text-gray-400 dark:text-gray-500">
                No conversations yet. Start a new chat!
            </div>
        </div>
    </div>

    {{-- Main Chat Area --}}
    <div class="ai-chat-main">
        {{-- Header --}}
        <div class="ai-chat-header">
            <button @click="showSidebar = !showSidebar" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="flex-1">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                    TechRisk AI
                    <span class="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,.5)]" :class="loading ? 'animate-pulse' : ''"></span>
                </h2>
                <div class="flex items-center gap-2">
                    <p class="text-xs text-gray-400 dark:text-gray-500">Ask anything about your incidents, patterns, trends, and data</p>
                    <span class="ai-chat-freshness" x-show="dataFreshness">
                        <span x-text="dataFreshnessLabel"></span>
                        <button @click="refreshContext()" class="ai-chat-refresh-btn" title="Refresh data">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </button>
                    </span>
                </div>
            </div>
            {{-- Compact "..." Menu --}}
            {{-- Chat Mode Toggle --}}
            <div class="relative flex rounded-lg bg-gray-100 dark:bg-gray-800 p-0.5 flex-shrink-0">
                {{-- Sliding pill indicator --}}
                <div class="absolute top-0.5 rounded-md transition-all duration-200 ease-out"
                     :style="{
                         width: 'calc(50% - 2px)',
                         height: 'calc(100% - 4px)',
                         left: selectedMode === 'normal' ? '2px' : 'calc(50%)',
                         backgroundColor: selectedMode === 'normal' ? '#4f46e5' : '#9333ea'
                     }">
                </div>
                <button @click="selectedMode = 'normal'"
                        class="relative z-10 px-3 py-1.5 text-xs font-medium rounded-md transition-colors duration-200 w-1/2 text-center"
                        :class="selectedMode === 'normal'
                            ? 'text-white'
                            : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                        title="Normal mode — fast streaming response">
                    Normal
                </button>
                <button @click="selectedMode = 'plan'"
                        class="relative z-10 px-3 py-1.5 text-xs font-medium rounded-md transition-colors duration-200 flex items-center justify-center gap-1 w-1/2"
                        :class="selectedMode === 'plan'
                            ? 'text-white'
                            : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                        title="Plan mode — think first, then dispatch specialist agents">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    Plan
                </button>
            </div>
            {{-- Model Picker --}}
            <div class="relative" x-data="{ showModelPicker: false }">
                <button @click="showModelPicker = !showModelPicker" class="ai-chat-model-btn">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <span x-text="selectedModelLabel" class="text-xs font-medium"></span>
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="showModelPicker" @click.away="showModelPicker = false"
                     x-transition class="ai-chat-model-dropdown">
                    <template x-for="(label, key) in models" :key="key">
                        <button @click="selectedModel = key; showModelPicker = false"
                                class="ai-chat-model-option"
                                :class="{ 'active': selectedModel === key }">
                            <span x-text="label"></span>
                        </button>
                    </template>
                </div>
            </div>
            {{-- Web Search Toggle --}}
            <button @click="webSearchEnabled = !webSearchEnabled"
                    class="ai-chat-model-btn"
                    :class="webSearchEnabled ? 'ai-chat-web-search--on' : ''"
                    :title="webSearchEnabled ? 'Web search ON — every message searches the internet' : 'Click to enable web search for all messages'">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                <span class="text-xs font-medium" x-text="webSearchEnabled ? 'Search ON' : 'Web Search'"></span>
            </button>
            {{-- Keyboard Shortcuts Help --}}
            <div class="relative" x-data="{ showHelp: false }">
                <button @click="showHelp = !showHelp" class="ai-chat-model-btn" title="Keyboard shortcuts">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </button>
                <div x-show="showHelp" @click.away="showHelp = false" x-transition
                     class="absolute right-0 top-full mt-2 w-72 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 p-4 z-50">
                    <h4 class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Keyboard Shortcuts</h4>
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-300">New conversation</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono">Ctrl+N</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-300">Focus input</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono">Ctrl+K</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-300">Toggle sidebar</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono">Ctrl+Shift+S</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-300">Search conversations</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono">Ctrl+Shift+F</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-300">Export chat</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono">Ctrl+Shift+E</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-300">Slash commands</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono">Ctrl+/</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-300">Send message</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono">Enter</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-300">New line</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono">Shift+Enter</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-300">Paste image</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono">Ctrl+V</kbd></div>
                    </div>
                </div>
            </div>
            {{-- Persona Selector --}}
            <div class="relative" x-data="{ showPersonaPicker: false }">
                <button @click="showPersonaPicker = !showPersonaPicker" class="ai-chat-model-btn" :class="selectedPersonas.length > 0 ? 'ai-chat-model-btn--active' : ''">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                    <span class="text-xs font-medium" x-text="selectedPersonas.length > 0 ? selectedPersonas.length + ' Personas' : 'Personas'"></span>
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="showPersonaPicker" @click.away="showPersonaPicker = false"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95 translate-y-1"
                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95 translate-y-1"
                     class="ai-chat-persona-panel">
                    <div class="ai-chat-persona-panel__header">
                        <div>
                            <h4 class="ai-chat-persona-panel__title">Specialist Personas</h4>
                            <p class="ai-chat-persona-panel__subtitle">Select analysts to give their unique perspective</p>
                        </div>
                        <template x-if="selectedPersonas.length > 0">
                            <button @click="selectedPersonas = []" type="button" class="ai-chat-persona-panel__clear">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M6 18L18 6M6 6l12 12"/></svg>
                                Clear
                            </button>
                        </template>
                    </div>
                    <div class="ai-chat-persona-panel__list">
                        <template x-for="agent in availableAgents" :key="agent.role_key">
                            <button @click="togglePersona(agent.role_key)"
                                    type="button"
                                    class="ai-chat-persona-card"
                                    :class="{ 'ai-chat-persona-card--selected': selectedPersonas.includes(agent.role_key) }"
                                    :style="'--p-color:' + getPersonaColor(agent.color)">
                                <div class="ai-chat-persona-card__glow"></div>
                                <div class="ai-chat-persona-card__inner">
                                    <div class="ai-chat-persona-card__avatar">
                                        <span x-text="getAgentInitial(agent.display_name)"></span>
                                    </div>
                                    <div class="ai-chat-persona-card__body">
                                        <div class="ai-chat-persona-card__header">
                                            <span class="ai-chat-persona-card__name" x-text="agent.display_name"></span>
                                            <span class="ai-chat-persona-card__role" x-text="agent.role_key"></span>
                                            <div class="ai-chat-persona-card__toggle"
                                                 :class="selectedPersonas.includes(agent.role_key) ? 'ai-chat-persona-card__toggle--on' : ''">
                                                <svg x-show="selectedPersonas.includes(agent.role_key)" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                        </div>
                                        <p x-show="agent.description" class="ai-chat-persona-card__desc" x-text="agent.description"></p>
                                        <div x-show="agent.skills && agent.skills.length > 0" class="ai-chat-persona-card__skills">
                                            <template x-for="skill in (agent.skills || []).slice(0, 5)" :key="skill">
                                                <span class="ai-chat-persona-card__skill" x-text="skill"></span>
                                            </template>
                                            <span x-show="(agent.skills || []).length > 5" class="ai-chat-persona-card__more" x-text="'+' + ((agent.skills || []).length - 5)"></span>
                                        </div>
                                    </div>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- Messages --}}
        <div class="ai-chat-messages" x-ref="messageContainer">
            {{-- Empty State --}}
            <div x-show="messages.length === 0 && !loading" class="ai-chat-empty">
                <div class="ai-chat-empty-icon">
                    <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"/></svg>
                </div>
                <h3>TechRisk AI</h3>
                <p>Your intelligent risk & incident analyst. Ask about incidents, patterns, trends, root causes, or financial impact.</p>
                <div class="ai-chat-suggestion-grid">
                    <button @click="inputText = '/summary this month'; sendMessage()" class="ai-chat-suggestion">
                        <span class="ai-chat-suggestion-icon">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </span>
                        <span class="ai-chat-suggestion-text">
                            <span class="ai-chat-suggestion-label">Monthly Summary</span>
                            <span class="ai-chat-suggestion-desc">/summary this month</span>
                        </span>
                    </button>
                    <button @click="inputText = '/risk'; sendMessage()" class="ai-chat-suggestion">
                        <span class="ai-chat-suggestion-icon">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </span>
                        <span class="ai-chat-suggestion-text">
                            <span class="ai-chat-suggestion-label">Risk Overview</span>
                            <span class="ai-chat-suggestion-desc">/risk</span>
                        </span>
                    </button>
                    <button @click="inputText = '/search '; $nextTick(() => $refs.chatInput?.focus())" class="ai-chat-suggestion">
                        <span class="ai-chat-suggestion-icon">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </span>
                        <span class="ai-chat-suggestion-text">
                            <span class="ai-chat-suggestion-label">Web Search</span>
                            <span class="ai-chat-suggestion-desc">/search the web</span>
                        </span>
                    </button>
                    <button @click="inputText = 'What patterns do you see in P1 and P2 incidents?'; sendMessage()" class="ai-chat-suggestion">
                        <span class="ai-chat-suggestion-icon">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>
                        </span>
                        <span class="ai-chat-suggestion-text">
                            <span class="ai-chat-suggestion-label">Severity Patterns</span>
                            <span class="ai-chat-suggestion-desc">Analyze P1 and P2 trends</span>
                        </span>
                    </button>
                </div>
            </div>

            {{-- Message List --}}
            <template x-for="(msg, idx) in messages" :key="msg.id">
                <div class="ai-chat-msg" :class="msg.role">
                    <div class="ai-chat-msg-avatar" :class="msg.role"
                         :style="msg.persona ? 'background:' + getPersonaColor(msg.persona.color, 0.15) + '; color:' + getPersonaColor(msg.persona.color) : ''">
                        <template x-if="msg.role === 'user'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </template>
                        <template x-if="msg.role === 'assistant' && !msg.persona">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
                        </template>
                        <template x-if="msg.role === 'assistant' && msg.persona">
                            <span class="text-xs font-bold" x-text="getAgentInitial(msg.persona.name)"></span>
                        </template>
                    </div>
                    <div class="ai-chat-msg-content" :class="[msg.role, msg.persona ? 'persona-border' : '']"
                         :style="msg.persona ? 'border-left: 3px solid ' + getPersonaColor(msg.persona.color, 0.6) : ''">
                        <template x-if="msg.role === 'user'">
                            <div>
                                <div x-show="msg.attachments && msg.attachments.length > 0" class="flex flex-wrap gap-2 mb-2">
                                    <template x-for="att in (msg.attachments || [])" :key="att.id">
                                        <span x-show="att.type === 'image'">
                                            <img :src="'/admin/ai/chat/attachment/' + att.id" class="w-20 h-20 object-cover rounded-lg border border-gray-200 dark:border-gray-600" loading="lazy" />
                                        </span>
                                        <span x-show="att.type === 'document'" class="inline-flex items-center gap-1 px-2 py-1 text-[11px] rounded-md bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">
                                            <span>📄</span>
                                            <span x-text="att.filename"></span>
                                        </span>
                                    </template>
                                </div>
                                <p class="text-sm" x-text="msg.content"></p>
                                <p class="ai-chat-msg-time" x-text="new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})"></p>
                            </div>
                        </template>
                        <template x-if="msg.role === 'assistant'">
                            <div>
                                <template x-if="msg.persona">
                                    <div class="flex items-center gap-1.5 mb-1.5">
                                        <span class="text-[11px] font-semibold" :style="'color:' + getPersonaColor(msg.persona.color)" x-text="msg.persona.name"></span>
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500">perspective</span>
                                    </div>
                                </template>
                                <div class="ai-chat-msg-text text-sm prose prose-sm dark:prose-invert max-w-none" x-html="msg.parsedHtml"></div>
                                <div class="flex items-center gap-2 mt-2">
                                    <template x-if="msg.persona">
                                        <span class="text-[10px] px-1.5 py-0.5 rounded" :style="'background:' + getPersonaColor(msg.persona.color, 0.1) + '; color:' + getPersonaColor(msg.persona.color)" x-text="msg.persona.name"></span>
                                    </template>
                                    <template x-if="!msg.persona">
                                        <span x-show="msg.model" class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400" x-text="msg.model"></span>
                                    </template>
                                    <template x-if="msg.web_search_used">
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 inline-flex items-center gap-1">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                                            Searched web
                                        </span>
                                    </template>
                                    <span x-show="msg.tokens_used" class="text-[10px] px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-500 dark:text-indigo-400" x-text="msg.prompt_tokens ? msg.prompt_tokens + '→' + msg.completion_tokens + ' (' + msg.tokens_used + ')' : msg.tokens_used + ' tokens'"></span>
                                    <button @click="copyMessage(msg.content)" class="ai-chat-action-btn" title="Copy">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                    </button>
                                    <button @click="regenerateResponse(idx)" class="ai-chat-action-btn" title="Regenerate">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    </button>
                                    <template x-if="msg.role === 'assistant' && msg.id && !String(msg.id).startsWith('temp-')">
                                        <div class="flex items-center gap-0.5 ml-1">
                                            <button @click="submitFeedback(msg.id, 'positive')" class="ai-chat-feedback-btn" :class="{'active positive': msg.feedback === 'positive'}" title="Helpful">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/><path d="M7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" stroke-linejoin="round"/></svg>
                                            </button>
                                            <button @click="submitFeedback(msg.id, 'negative')" class="ai-chat-feedback-btn" :class="{'active negative': msg.feedback === 'negative'}" title="Not helpful">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10 15v4a3 3 0 003 3l4-9V2H5.72a2 2 0 00-2 1.7l-1.38 9a2 2 0 002 2.3H10z"/><path d="M17 2h2.67A2.31 2.31 0 0122 4v7a2.31 2.31 0 01-2.33 2H17" stroke-linejoin="round"/></svg>
                                            </button>
                                        </div>
                                    </template>
                                    <span x-show="msg.copied" class="text-[10px] text-green-500 dark:text-green-400">Copied!</span>
                                </div>
                                {{-- Follow-up suggestions --}}
                                <div x-show="msg.follow_ups && msg.follow_ups.length > 0" class="ai-chat-followups">
                                    <template x-for="(q, qi) in (msg.follow_ups || [])" :key="qi">
                                        <button @click="askFollowUp(q)" class="ai-chat-followup-btn" x-text="q"></button>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Typing Indicator --}}
            <div x-show="loading" x-transition class="ai-chat-msg assistant">
                <template x-if="selectedPersonas.length > 0">
                    <div class="flex flex-col gap-2 w-full">
                        <div class="flex items-center gap-2">
                            <div class="flex -space-x-1.5">
                                <template x-for="(roleKey, i) in selectedPersonas" :key="roleKey">
                                    <span class="ai-chat-persona-icon relative" :style="'width:24px;height:24px;font-size:9px;border:2px solid white;dark:border-gray-800;background:' + getPersonaColor(getAgentByKey(roleKey)?.color || 'gray', 0.15) + '; color:' + getPersonaColor(getAgentByKey(roleKey)?.color || 'gray')">
                                        <span x-text="getAgentInitial(getAgentByKey(roleKey)?.display_name || '?')"></span>
                                    </span>
                                </template>
                            </div>
                            <div class="ai-chat-typing"><span></span><span></span><span></span></div>
                            <span class="text-[11px] text-gray-400 dark:text-gray-500">Preparing <span x-text="selectedPersonas.length"></span> perspectives...</span>
                        </div>
                        <div class="flex items-center gap-2 ml-8">
                            <span class="text-xs text-gray-400 dark:text-gray-500" x-ref="elapsedDisplay" x-show="loading"></span>
                            <button @click="stopGeneration()" class="ai-chat-stop-btn">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
                                Stop
                            </button>
                        </div>
                    </div>
                </template>
                <template x-if="selectedPersonas.length === 0">
                    <div class="flex items-center gap-1">
                        <div class="ai-chat-msg-avatar assistant">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
                        </div>
                        <div class="ai-chat-msg-content assistant">
                            <div class="flex items-center gap-2">
                                <div class="ai-chat-typing"><span></span><span></span><span></span></div>
                                <span class="text-xs text-gray-400 dark:text-gray-500" x-ref="elapsedDisplay2" x-show="loading"></span>
                                <button @click="stopGeneration()" class="ai-chat-stop-btn">
                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
                                    Stop
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Input --}}
        <div class="ai-chat-input-area">
            {{-- Referenced incidents chips --}}
            <div x-show="referencedIncidents.length > 0 || pendingAttachments.length > 0" x-transition class="ai-chat-ref-bar">
                <template x-for="(inc, idx) in referencedIncidents" :key="inc.no">
                    <span class="ai-chat-ref-chip">
                        <span class="ai-chat-ref-severity" :class="'severity-' + (inc.severity || '').toLowerCase().replace(' ', '')" x-text="inc.severity"></span>
                        <span class="ai-chat-ref-text" x-text="inc.no + (inc.title ? ': ' + inc.title.substring(0, 40) : '')"></span>
                        <button @click="removeReferencedIncident(idx)" class="ai-chat-ref-remove">&times;</button>
                    </span>
                </template>
                <template x-for="(att, idx) in pendingAttachments" :key="att.id">
                    <span class="ai-chat-ref-chip" style="background: #f0fdf4; border-color: #86efac;">
                        <span x-text="att.type === 'image' ? '🖼' : '📄'"></span>
                        <span class="ai-chat-ref-text" x-text="(att.filename || 'Attachment').substring(0, 40)"></span>
                        <button @click="removeAttachment(idx)" class="ai-chat-ref-remove">&times;</button>
                    </span>
                </template>
                <button x-show="referencedIncidents.length > 0" @click="referencedIncidents = []" class="ai-chat-ref-clear">Clear all</button>
            </div>

            {{-- Image previews --}}
            <div x-show="pendingAttachments.filter(a => a.type === 'image').length > 0" class="flex gap-2 px-3 pt-2 overflow-x-auto">
                <template x-for="(att, idx) in pendingAttachments.filter(a => a.type === 'image')" :key="att.id">
                    <div class="relative flex-shrink-0">
                        <img :src="att.previewUrl" class="w-16 h-16 object-cover rounded-lg border border-gray-200 dark:border-gray-600" />
                        <button @click="removeAttachment(pendingAttachments.indexOf(att))" class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full text-[10px] flex items-center justify-center leading-none">&times;</button>
                    </div>
                </template>
            </div>

            <div class="ai-chat-input-wrapper relative">
                {{-- Slash command autocomplete --}}
                <div x-show="slashActive && filteredCommands.length > 0" x-transition class="ai-chat-slash-dropdown">
                    <template x-for="(cmd, ci) in filteredCommands" :key="ci">
                        <button @click="selectSlashCommand(cmd.cmd)" class="ai-chat-slash-item" :class="{ 'active': slashIndex === ci }">
                            <span class="ai-chat-slash-cmd" x-text="cmd.cmd"></span>
                            <span class="ai-chat-slash-desc" x-text="cmd.desc"></span>
                        </button>
                    </template>
                </div>
                <textarea x-model="inputText"
                          @keydown.enter.prevent="if (!$event.shiftKey) sendMessage()"
                          @keydown.shift.enter=""
                          @keydown.down.prevent="if (slashActive) slashIndex = Math.min(slashIndex + 1, filteredCommands.length - 1)"
                          @keydown.up.prevent="if (slashActive) slashIndex = Math.max(slashIndex - 1, 0)"
                          @keydown.tab.prevent="if (slashActive && filteredCommands[slashIndex]) selectSlashCommand(filteredCommands[slashIndex].cmd)"
                          @keydown.escape.window="slashActive = false"
                          @paste="handlePaste($event)"
                          x-ref="chatInput"
                          rows="1"
                          placeholder="Ask about incidents, patterns, trends... or type / for commands"
                          class="ai-chat-textarea"
                          :disabled="loading"
                          @input="onInput()"></textarea>

                {{-- Attach File button --}}
                <input type="file" x-ref="fileInput" class="hidden" accept="image/png,image/jpeg,image/gif,image/webp,.pdf,.docx,.doc" @change="handleFileSelect($event)" multiple />
                <button @click="$refs.fileInput.click()" type="button" class="ai-chat-attach-btn" :class="{'has-refs': pendingAttachments.length > 0}" title="Attach image or document">
                    <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                    <span x-show="pendingAttachments.length > 0" class="ai-chat-attach-badge" x-text="pendingAttachments.length"></span>
                </button>

                {{-- Attach Incident button --}}
                <div class="relative">
                    <button @click="showIncidentPicker = !showIncidentPicker" type="button" class="ai-chat-attach-btn" :class="{'has-refs': referencedIncidents.length > 0}" title="Reference incidents">
                        <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        <span x-show="referencedIncidents.length > 0" class="ai-chat-attach-badge" x-text="referencedIncidents.length"></span>
                    </button>

                    {{-- Incident picker dropdown --}}
                    <div x-show="showIncidentPicker" @click.away="showIncidentPicker = false" x-transition class="ai-chat-picker-dropdown">
                        <div class="ai-chat-picker-header">
                            <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">Reference Incidents</span>
                        </div>

                        <input type="text" x-model="incidentSearch" @input.debounce.300ms="searchIncidents()" placeholder="Search by ID, title, or summary..." class="ai-chat-picker-search" x-ref="incidentSearchInput" />
                        <div class="ai-chat-picker-list">
                            <template x-for="inc in incidentResults" :key="inc.no">
                                <button @click="toggleIncidentRef(inc)" type="button" class="ai-chat-picker-item" :class="{'selected': isIncidentReferenced(inc.no)}">
                                    <span class="ai-chat-ref-severity" :class="'severity-' + (inc.severity || '').toLowerCase().replace(' ', '')" x-text="inc.severity"></span>
                                    <div class="flex-1 min-w-0 text-left">
                                        <p class="text-xs font-medium truncate" x-text="inc.no"></p>
                                        <p class="text-[11px] text-gray-400 dark:text-gray-500 truncate" x-text="inc.title"></p>
                                    </div>
                                    <span class="text-[10px] text-gray-400 dark:text-gray-500" x-text="inc.date"></span>
                                    <span x-show="isIncidentReferenced(inc.no)" class="text-green-500 dark:text-green-400 text-xs">&#10003;</span>
                                </button>
                            </template>
                            <div x-show="incidentResults.length === 0 && incidentSearch.length >= 2 && !incidentSearchLoading" class="p-3 text-center text-xs text-gray-400 dark:text-gray-500">No incidents found</div>
                            <div x-show="incidentSearchLoading" class="p-3 text-center text-xs text-gray-400 dark:text-gray-500">Searching...</div>
                            <div x-show="incidentSearch.length < 2" class="p-3 text-center text-xs text-gray-400 dark:text-gray-500">Type at least 2 characters to search</div>
                        </div>
                    </div>
                </div>

                {{-- Export --}}
                <div class="relative" x-data="{ showExportMenu: false }">
                    <button @click="showExportMenu = !showExportMenu" type="button"
                            :class="{ 'opacity-40 pointer-events-none': !activeConversationId || messages.length === 0 }"
                            class="ai-chat-attach-btn"
                            title="Export conversation">
                        <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </button>
                    <div x-show="showExportMenu" @click.away="showExportMenu = false" x-transition
                         class="absolute bottom-full right-0 mb-1 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 py-1 min-w-[140px] z-50">
                        <a :href="activeConversationId ? '/admin/ai/chat/conversations/' + activeConversationId + '/export-pdf?format=pdf' : '#'"
                           target="_blank" @click="showExportMenu = false"
                           class="block px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                            PDF Document
                        </a>
                        <a :href="activeConversationId ? '/admin/ai/chat/conversations/' + activeConversationId + '/export-pdf?format=markdown' : '#'"
                           target="_blank" @click="showExportMenu = false"
                           class="block px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                            Markdown
                        </a>
                        <a :href="activeConversationId ? '/admin/ai/chat/conversations/' + activeConversationId + '/export-pdf?format=json' : '#'"
                           target="_blank" @click="showExportMenu = false"
                           class="block px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                            JSON
                        </a>
                    </div>
                </div>
                <button @click="sendMessage()" :disabled="loading || !inputText.trim()" class="ai-chat-send-btn">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                </button>
            </div>
            <p class="text-[10px] text-gray-400 dark:text-gray-500 text-center mt-1.5">AI can produce inaccurate information. Always verify important data. <span x-text="'Model: ' + selectedModelLabel"></span> <span x-show="selectedMode === 'plan'" class="font-medium text-purple-500" x-text="'| Mode: Plan'"></span></p>
            <p x-show="selectedPersonas.length >= 3" class="text-[10px] text-amber-500 dark:text-amber-400 text-center mt-0.5" x-text="selectedPersonas.length + ' personas will generate separate responses, increasing token usage.'"></p>
        </div>
    </div>
</div>

<script>
function aiChat() {
    const slashCommands = {{ Js::from(config('ai.chat_slash_commands', [])) }};
    // Non-reactive internal state (won't trigger Alpine re-renders)
    let _elapsed = 0;
    let _timer = null;

    const colorMap = {
        blue: '#3b82f6', indigo: '#6366f1', purple: '#8b5cf6', green: '#22c55e',
        teal: '#14b8a6', cyan: '#06b6d4', red: '#ef4444', orange: '#f97316',
        amber: '#f59e0b', pink: '#ec4899', emerald: '#10b981', gray: '#6b7280',
        rose: '#f43f5e', fuchsia: '#d946ef', sky: '#0ea5e9', violet: '#8b5cf6',
        yellow: '#eab308',
    };

    // Markdown parser — caches result on message objects
    function parseMd(text) {
        if (!text) return '';
        // Strip AI reasoning/thinking tags (defense-in-depth for server-side filtering)
        text = text.replace(/<think(?:ing)?[^>]*>[\s\S]*?<\/think(?:ing)?>/gi, '');
        if (typeof marked !== 'undefined') {
            try { return marked.parse(text, { breaks: true, gfm: true }); }
            catch { return text.replace(/\n/g, '<br>'); }
        }
        return text.replace(/\n/g, '<br>');
    }

    // Enrich a message object with parsedHtml
    function withHtml(msg) {
        if (msg.role === 'assistant' && !msg.parsedHtml) {
            msg.parsedHtml = parseMd(msg.content);
        }
        return msg;
    }

    return {
        conversations: [],
        activeConversationId: null,
        messages: [],
        inputText: '',
        loading: false,
        activePersonaKey: null,
        selectedModel: '{{ $defaultModel }}',
        models: {{ Js::from($models) }},
        showSidebar: false,
        dataFreshness: null,
        slashActive: false,
        slashIndex: 0,
        abortController: null,
        lastUserMessage: '',
        searchQuery: '',
        referencedIncidents: [],
        showIncidentPicker: false,
        incidentSearch: '',
        incidentResults: [],
        incidentSearchLoading: false,
        availableAgents: [],
        selectedPersonas: [],
        webSearchEnabled: false,
        selectedMode: 'normal',
        planState: null,
        clarificationState: null,
        clarificationAnswers: [],
        gapAnalysisState: null,
        researchState: null,
        editingTitleId: null,
        editingTitleValue: '',
        collapsedGroups: {},
        pendingAttachments: [],

        get selectedModelLabel() {
            return this.models[this.selectedModel] || this.selectedModel;
        },

        get dataFreshnessLabel() {
            if (!this.dataFreshness) return '';
            if (this.dataFreshness.stats_cached) return 'Data: cached';
            return 'Data: fresh';
        },

        get groupedConversations() {
            const nonPinned = this.conversations.filter(c => {
                if (this.searchQuery) return true;
                return !c.pinned;
            });
            const now = new Date();
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const yesterday = new Date(today); yesterday.setDate(yesterday.getDate() - 1);
            const weekStart = new Date(today); weekStart.setDate(weekStart.getDate() - weekStart.getDay());
            const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
            const groups = [
                { label: 'Today', cutoff: today, items: [] },
                { label: 'Yesterday', cutoff: yesterday, items: [] },
                { label: 'This Week', cutoff: weekStart, items: [] },
                { label: 'This Month', cutoff: monthStart, items: [] },
                { label: 'Older', cutoff: null, items: [] },
            ];
            for (const conv of nonPinned) {
                const d = new Date(conv.updated_at);
                let placed = false;
                for (const g of groups) {
                    if (!g.cutoff || d >= g.cutoff) { g.items.push(conv); placed = true; break; }
                }
                if (!placed) groups[groups.length - 1].items.push(conv);
            }
            return groups.filter(g => g.items.length > 0);
        },

        get filteredCommands() {
            if (!this.inputText.startsWith('/')) return [];
            const q = this.inputText.toLowerCase().slice(1);
            return Object.entries(slashCommands)
                .filter(([cmd]) => cmd.startsWith(q))
                .map(([cmd, desc]) => ({ cmd: '/' + cmd, desc }));
        },

        init() {
            this.loadConversations();
            this.loadAgents();
            if (window.innerWidth >= 1024) this.showSidebar = true;

            if (typeof mermaid !== 'undefined') {
                mermaid.initialize({
                    startOnLoad: false,
                    theme: document.documentElement.classList.contains('dark') ? 'dark' : 'default',
                });
            }

            document.addEventListener('livewire:navigating', () => {
                if (_timer) clearInterval(_timer);
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                const mod = e.metaKey || e.ctrlKey;
                if (!mod) {
                    if (e.key === 'Escape') {
                        this.slashActive = false;
                        this.showIncidentPicker = false;
                        this.showModelPicker = false;
                        this.showPersonaPicker = false;
                    }
                    return;
                }
                // Cmd/Ctrl + N: New conversation
                if (e.key === 'n' && !e.shiftKey) {
                    e.preventDefault();
                    this.newConversation();
                    this.$nextTick(() => this.$refs.chatInput?.focus());
                }
                // Cmd/Ctrl + K: Focus input
                if (e.key === 'k' && !e.shiftKey) {
                    e.preventDefault();
                    this.$refs.chatInput?.focus();
                }
                // Cmd/Ctrl + Shift + S: Toggle sidebar
                if (e.key === 'S' || (e.key === 's' && e.shiftKey)) {
                    e.preventDefault();
                    this.showSidebar = !this.showSidebar;
                }
                // Cmd/Ctrl + Shift + F: Focus conversation search
                if (e.key === 'F' || (e.key === 'f' && e.shiftKey)) {
                    e.preventDefault();
                    this.showSidebar = true;
                    this.$nextTick(() => {
                        const searchEl = this.$el.querySelector('.ai-chat-search-input');
                        if (searchEl) searchEl.focus();
                    });
                }
                // Cmd/Ctrl + Shift + E: Export PDF
                if (e.key === 'E' || (e.key === 'e' && e.shiftKey)) {
                    e.preventDefault();
                    if (this.activeConversationId && this.messages.length > 0) {
                        window.open('/admin/ai/chat/conversations/' + this.activeConversationId + '/export-pdf', '_blank');
                    }
                }
                // Cmd/Ctrl + /: Show slash commands
                if (e.key === '/') {
                    e.preventDefault();
                    this.inputText = '/';
                    this.$nextTick(() => {
                        this.$refs.chatInput?.focus();
                        this.onInput();
                    });
                }
            });
        },

        onInput() {
            this.autoResize();
            this.slashActive = this.inputText.startsWith('/') && this.filteredCommands.length > 0;
            this.slashIndex = 0;
        },

        getAgentInitial(name) {
            if (!name) return '?';
            const words = name.trim().split(/\s+/);
            if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
            return name.slice(0, 2).toUpperCase();
        },

        getPersonaColor(colorName, alpha = 1) {
            const hex = colorMap[colorName] || colorMap.gray;
            if (alpha === 1) return hex;
            const r = parseInt(hex.slice(1, 3), 16);
            const g = parseInt(hex.slice(3, 5), 16);
            const b = parseInt(hex.slice(5, 7), 16);
            return `rgba(${r}, ${g}, ${b}, ${alpha})`;
        },

        getAgentByKey(roleKey) {
            return this.availableAgents.find(a => a.role_key === roleKey);
        },

        togglePersona(roleKey) {
            const idx = this.selectedPersonas.indexOf(roleKey);
            if (idx === -1) this.selectedPersonas.push(roleKey);
            else this.selectedPersonas.splice(idx, 1);
        },

        async loadAgents() {
            try {
                const res = await fetch('/admin/ai/chat/agents', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) this.availableAgents = await res.json();
            } catch (e) { console.error('Failed to load agents:', e); }
        },

        selectSlashCommand(cmd) {
            this.inputText = cmd + ' ';
            this.slashActive = false;
            this.$nextTick(() => this.$refs.chatInput?.focus());
        },

        askFollowUp(question) {
            this.inputText = question;
            this.sendMessage();
        },

        async refreshContext() {
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                const res = await fetch('/admin/ai/chat/refresh-context', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) {
                    const data = await res.json();
                    this.dataFreshness = data.freshness;
                }
            } catch (e) { console.error(e); }
        },

        async loadConversations() {
            this.searchQuery = '';
            try {
                const res = await fetch('/admin/ai/chat/conversations', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) {
                    const data = await res.json();
                    this.conversations = data.conversations;
                }
            } catch (e) { console.error(e); }
        },

        async searchConversations() {
            try {
                const params = new URLSearchParams();
                if (this.searchQuery && this.searchQuery.length >= 2) {
                    params.set('search', this.searchQuery);
                }
                const res = await fetch('/admin/ai/chat/conversations?' + params.toString(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) {
                    const data = await res.json();
                    this.conversations = data.conversations;
                }
            } catch (e) { console.error(e); }
        },

        async selectConversation(id) {
            if (this.activeConversationId === id) return;
            this.activeConversationId = id;
            this.messages = [];
            try {
                const res = await fetch(`/admin/ai/chat/conversations/${id}/messages`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) {
                    const data = await res.json();
                    this.messages = data.messages.map(m => {
                        // Reconstruct plan card UI for persisted plan messages
                        if (m.is_plan_message && m.plan_role === 'plan' && m.plan_metadata) {
                            const planData = {
                                plan_text: m.plan_metadata.plan_text || m.content,
                                subtasks: (m.plan_metadata.subtasks || []).map((s, i) => ({
                                    index: i,
                                    description: s.description,
                                    persona_key: s.persona_key,
                                    label: s.label,
                                    status: 'completed',
                                })),
                            };
                            m.parsedHtml = this.renderPlanCards(planData);
                            m.isPlanMessage = true;
                            m.planRole = 'plan';
                        }
                        // Skip ephemeral thinking messages on reload
                        if (m.is_plan_message && m.plan_role === 'thinking') {
                            return null;
                        }
                        return withHtml(m);
                    }).filter(m => m !== null);
                    if (data.conversation?.model) this.selectedModel = data.conversation.model;
                    this.restoreReferencedIncidents();
                    // Restore persona selection from conversation history
                    const personaKeys = new Set();
                    for (const msg of data.messages) {
                        if (msg.persona && msg.persona.key) personaKeys.add(msg.persona.key);
                    }
                    this.selectedPersonas = [...personaKeys];
                    this.$nextTick(() => { this.scrollToBottom(); this.scheduleMermaidRender(); });
                }
            } catch (e) { console.error(e); }
            if (window.innerWidth < 1024) this.showSidebar = false;
        },

        async newConversation() {
            this.activeConversationId = null;
            this.messages = [];
            this.referencedIncidents = [];
            this.pendingAttachments = [];
            if (window.innerWidth < 1024) this.showSidebar = false;
        },

        async deleteConversation(id) {
            if (!confirm('Delete this conversation?')) return;
            try {
                const res = await fetch(`/admin/ai/chat/conversations/${id}`, {
                    method: 'DELETE',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
                });
                if (res.ok) {
                    this.conversations = this.conversations.filter(c => c.id !== id);
                    if (this.activeConversationId === id) {
                        this.activeConversationId = null;
                        this.messages = [];
                    }
                }
            } catch (e) { console.error(e); }
        },

        async togglePin(conv) {
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                const res = await fetch('/admin/ai/chat/conversations/' + conv.id + '/pin', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) {
                    const data = await res.json();
                    this.conversations = this.conversations.map(c =>
                        c.id === conv.id ? { ...c, pinned: data.pinned } : c
                    );
                }
            } catch (e) { console.error(e); }
        },

        startEditTitle(conv) {
            this.editingTitleId = conv.id;
            this.editingTitleValue = conv.title || '';
            this.$nextTick(() => {
                const el = document.getElementById('title-input-' + conv.id);
                if (el) { el.focus(); el.select(); }
            });
        },

        async saveTitle(conv) {
            const title = this.editingTitleValue.trim();
            if (!title || title === conv.title) { this.editingTitleId = null; return; }
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                const res = await fetch('/admin/ai/chat/conversations/' + conv.id + '/title', {
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ title }),
                });
                if (res.ok) {
                    const data = await res.json();
                    this.conversations = this.conversations.map(c =>
                        c.id === conv.id ? { ...c, title: data.title } : c
                    );
                }
            } catch (e) { console.error(e); }
            this.editingTitleId = null;
        },

        cancelEditTitle() {
            this.editingTitleId = null;
        },

        async handleFileSelect(event) {
            const files = event.target.files;
            if (!files || files.length === 0) return;
            for (const file of files) {
                await this.uploadAttachment(file);
            }
            event.target.value = '';
        },

        handlePaste(event) {
            const items = event.clipboardData?.items;
            if (!items) return;
            for (const item of items) {
                if (item.type.startsWith('image/')) {
                    event.preventDefault();
                    const file = item.getAsFile();
                    if (file) this.uploadAttachment(file);
                    return;
                }
            }
        },

        async uploadAttachment(file) {
            const maxSize = file.type.startsWith('image/') ? 5 * 1024 * 1024 : 15 * 1024 * 1024;
            if (file.size > maxSize) {
                alert(`File too large. Maximum ${file.type.startsWith('image/') ? '5MB' : '15MB'}.`);
                return;
            }
            const formData = new FormData();
            formData.append('file', file);
            const token = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value || '';
            try {
                const res = await fetch('/admin/ai/chat/upload-attachment', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token },
                    body: formData,
                });
                const data = await res.json();
                if (data.success && data.attachment) {
                    const att = data.attachment;
                    if (att.type === 'image' && file.type.startsWith('image/')) {
                        att.previewUrl = URL.createObjectURL(file);
                    }
                    this.pendingAttachments.push(att);
                } else {
                    alert(data.error || 'Failed to upload attachment.');
                }
            } catch (e) {
                alert('Failed to upload attachment. Please try again.');
            }
        },

        removeAttachment(idx) {
            const att = this.pendingAttachments[idx];
            if (att?.previewUrl) URL.revokeObjectURL(att.previewUrl);
            this.pendingAttachments.splice(idx, 1);
        },

        async sendMessage() {
            const text = this.inputText.trim();
            if (!text) return;

            // Safety: reset stuck loading state
            if (this.loading) {
                this.loading = false;
                if (_timer) { clearInterval(_timer); _timer = null; }
                _elapsed = 0;
            }

            this.slashActive = false;

            // Include referenced incident IDs in message display
            const refIds = this.referencedIncidents.map(i => i.no);
            const refPrefix = refIds.length > 0 ? refIds.map(no => `[${no}]`).join(' ') + '\n' : '';

            const attachmentsToSend = [...this.pendingAttachments];
            const userMsg = {
                id: 'temp-' + Date.now(),
                role: 'user',
                content: refPrefix + text,
                created_at: new Date().toISOString(),
                attachments: attachmentsToSend.length > 0 ? attachmentsToSend.map(a => ({id: a.id, type: a.type, filename: a.filename, mime_type: a.mime_type, size: a.size})) : undefined,
            };
            this.messages = [...this.messages, userMsg];
            this.inputText = '';
            this.pendingAttachments = [];
            this.autoResize();
            this.scrollToBottom();

            this.loading = true;
            this.activePersonaKey = null;
            _elapsed = 0;
            this.lastUserMessage = text;
            _timer = setInterval(() => {
                _elapsed++;
                const el = document.querySelector('[x-ref="elapsedDisplay"]') || document.querySelector('[x-ref="elapsedDisplay2"]');
                if (el) el.textContent = _elapsed + 's';
            }, 1000);
            this.abortController = new AbortController();

            // Route: streaming personas vs standard
            await this.sendMessageStream(text, refIds, attachmentsToSend);

            this.loading = false;
            if (_timer) { clearInterval(_timer); _timer = null; }
            _elapsed = 0;
            this.showIncidentPicker = false;
            this.$nextTick(() => this.scrollToBottom());
        },

        async sendMessageStream(text, refIds, attachmentsToSend = []) {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const isPersona = this.selectedPersonas.length > 0;
            const isPlan = this.selectedMode === 'plan';

            let endpoint;
            if (isPlan) {
                endpoint = '/admin/ai/chat/stream-plan';
            } else if (isPersona) {
                endpoint = '/admin/ai/chat/stream-personas';
            } else {
                endpoint = '/admin/ai/chat/stream';
            }

            try {
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'text/event-stream',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: this.abortController.signal,
                    body: JSON.stringify({
                        message: text,
                        conversation_id: this.activeConversationId,
                        model: this.selectedModel,
                        referenced_incidents: refIds,
                        personas: isPlan || isPersona ? this.selectedPersonas : undefined,
                        web_search: this.webSearchEnabled,
                        mode: isPlan ? 'plan' : 'normal',
                        attachments: attachmentsToSend.length > 0 ? attachmentsToSend.map(a => ({id: a.id, type: a.type, filename: a.filename, mime_type: a.mime_type, size: a.size})) : undefined,
                    }),
                });

                if (res.status === 419) { window.location.reload(); return; }
                if (res.status === 429) {
                    this.messages = [...this.messages, withHtml({ id: 'error-' + Date.now(), role: 'assistant', content: '⚠️ Rate limit exceeded. Please wait a moment.', model: null, created_at: new Date().toISOString() })];
                    return;
                }
                if (!res.ok) {
                    this.messages = [...this.messages, withHtml({ id: 'error-' + Date.now(), role: 'assistant', content: '⚠️ Server error (' + res.status + '). Please try again.', model: null, created_at: new Date().toISOString() })];
                    return;
                }

                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let streamingIdx = -1;
                let streamingContent = '';
                let setupData = null;
                let personaIdx = {};
                let personaRaw = {};
                let renderQueued = false;
                let personaRenderQueued = {};

                const queueRender = (idx, content, personaKey) => {
                    const queueKey = personaKey || '_standard';
                    const queueMap = personaKey ? personaRenderQueued : null;
                    if (queueMap) {
                        if (queueMap[queueKey]) return;
                        queueMap[queueKey] = true;
                    } else if (renderQueued) {
                        return;
                    } else {
                        renderQueued = true;
                    }
                    requestAnimationFrame(() => {
                        if (queueMap) {
                            queueMap[queueKey] = false;
                        } else {
                            renderQueued = false;
                        }
                        if (idx >= 0 && idx < this.messages.length) {
                            const msg = { ...this.messages[idx] };
                            msg.content = content;
                            msg._raw = content;
                            msg.parsedHtml = parseMd(content) + '<span class="ai-chat-cursor">▌</span>';
                            this.messages[idx] = msg;
                            this.scrollToBottom();
                        }
                    });
                };

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const parts = buffer.split('\n\n');
                    buffer = parts.pop() || '';

                    for (const part of parts) {
                        const lines = part.split('\n');
                        let eventType = '';
                        let dataStr = '';

                        for (const line of lines) {
                            if (line.startsWith('event: ')) eventType = line.slice(7).trim();
                            else if (line.startsWith('data: ')) dataStr += line.slice(6);
                        }

                        if (eventType === 'setup') {
                            const data = JSON.parse(dataStr);
                            setupData = data;
                            if (data.is_new && data.conversation_id) {
                                this.activeConversationId = data.conversation_id;
                            }
                        } else if (eventType === 'error') {
                            const data = JSON.parse(dataStr);
                            this.messages = [...this.messages, withHtml({ id: 'error-' + Date.now(), role: 'assistant', content: '⚠️ ' + (data.error || 'Something went wrong.'), model: null, created_at: new Date().toISOString() })];
                        } else if (eventType === 'plan_thinking') {
                            this.loading = false;
                            if (_timer) { clearInterval(_timer); _timer = null; }
                            this.planState = { phase: 'thinking' };
                            const thinkingMsg = {
                                id: 'plan-thinking-' + Date.now(),
                                role: 'assistant',
                                content: '',
                                parsedHtml: '<div class="flex items-center gap-2 text-purple-600 dark:text-purple-400 p-3"><svg class="w-5 h-5 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg><span class="text-sm font-medium">Thinking and planning...</span></div>',
                                isPlanMessage: true,
                                planRole: 'thinking',
                                isStreaming: true,
                                created_at: new Date().toISOString(),
                            };
                            const clarCheckIdx = this.messages.findIndex(m => m.planRole === 'clarification_check');
                            if (clarCheckIdx >= 0) {
                                this.messages[clarCheckIdx] = thinkingMsg;
                                this.messages = [...this.messages];
                            } else {
                                this.messages = [...this.messages, thinkingMsg];
                            }
                            this.scrollToBottom();
                        } else if (eventType === 'plan_ready') {
                            const data = JSON.parse(dataStr);
                            this.planState = { phase: 'agents', planText: data.plan_text, subtasks: data.subtasks };
                            const planHtml = this.renderPlanCards(data);
                            const thinkIdx = this.messages.findIndex(m => m.planRole === 'thinking');
                            if (thinkIdx >= 0) {
                                const msg = { ...this.messages[thinkIdx] };
                                msg.parsedHtml = planHtml;
                                msg.content = data.plan_text;
                                msg.planRole = 'plan';
                                msg.isStreaming = false;
                                this.messages[thinkIdx] = msg;
                            }
                            this.scrollToBottom();
                        } else if (eventType === 'agent_status') {
                            const data = JSON.parse(dataStr);
                            if (this.planState) {
                                this.planState.subtasks[data.index] = { ...this.planState.subtasks[data.index], ...data };
                                const planIdx = this.messages.findIndex(m => m.planRole === 'plan');
                                if (planIdx >= 0) {
                                    const msg = { ...this.messages[planIdx] };
                                    msg.parsedHtml = this.renderPlanCards(this.planState);
                                    this.messages[planIdx] = msg;
                                }
                                this.scrollToBottom();
                            }
                        } else if (eventType === 'plan_fallback') {
                            const data = JSON.parse(dataStr);
                            const thinkIdx = this.messages.findIndex(m => m.planRole === 'thinking' || m.planRole === 'plan');
                            if (thinkIdx >= 0) {
                                const msg = { ...this.messages[thinkIdx] };
                                msg.parsedHtml = '<div class="p-3 text-amber-600 dark:text-amber-400 text-sm"><p>Fallback to standard mode: ' + (data.reason || 'Planning unavailable') + '</p></div>';
                                msg.planRole = 'fallback';
                                msg.isStreaming = false;
                                this.messages[thinkIdx] = msg;
                            }
                            this.planState = null;
                        } else if (eventType === 'clarification_check') {
                            this.messages = [...this.messages, {
                                id: 'clarification-check-' + Date.now(),
                                role: 'assistant',
                                content: '',
                                parsedHtml: '<div class="flex items-center gap-2 text-blue-600 dark:text-blue-400 p-3"><svg class="w-4 h-4 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span class="text-sm">Checking if your question needs clarification...</span></div>',
                                isPlanMessage: true,
                                planRole: 'clarification_check',
                                isStreaming: true,
                                created_at: new Date().toISOString(),
                            }];
                            this.scrollToBottom();
                        } else if (eventType === 'needs_clarification') {
                            const data = JSON.parse(dataStr);
                            this.loading = false;
                            if (_timer) { clearInterval(_timer); _timer = null; }
                            this.clarificationState = {
                                planId: data.plan_id,
                                conversationId: data.conversation_id,
                                questions: data.questions,
                            };
                            this.clarificationAnswers = data.questions.map(() => '');
                            const checkIdx = this.messages.findIndex(m => m.planRole === 'clarification_check');
                            if (checkIdx >= 0) {
                                const msg = { ...this.messages[checkIdx] };
                                msg.parsedHtml = this.renderClarificationUI(data.questions);
                                msg.planRole = 'clarification';
                                msg.isStreaming = false;
                                this.messages[checkIdx] = msg;
                            }
                            this.scrollToBottom();
                        } else if (eventType === 'gap_analysis') {
                            const data = JSON.parse(dataStr);
                            this.gapAnalysisState = data;
                            if (data.status === 'analyzing') {
                                const planIdx = this.messages.findIndex(m => m.planRole === 'plan');
                                if (planIdx >= 0) {
                                    const msg = { ...this.messages[planIdx] };
                                    msg.parsedHtml = this.renderPlanCards(this.planState)
                                        + '<div class="mt-2 p-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50">'
                                        + '<div class="flex items-center gap-2 text-amber-600 dark:text-amber-400">'
                                        + '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>'
                                        + '<span class="text-xs font-medium">Analyzing results for completeness...</span></div></div>';
                                    this.messages[planIdx] = msg;
                                }
                            }
                            this.scrollToBottom();
                        } else if (eventType === 'research_start') {
                            const data = JSON.parse(dataStr);
                            this.researchState = { topics: data.topics, count: data.count };
                            const planIdx = this.messages.findIndex(m => m.planRole === 'plan');
                            if (planIdx >= 0) {
                                const msg = { ...this.messages[planIdx] };
                                msg.parsedHtml = this.renderPlanCards(this.planState) + this.renderResearchCards(data);
                                this.messages[planIdx] = msg;
                            }
                            this.scrollToBottom();
                        } else if (eventType === 'research_status') {
                            const data = JSON.parse(dataStr);
                            if (this.planState) {
                                if (!this.planState.researchSubtasks) this.planState.researchSubtasks = [];
                                const existing = this.planState.researchSubtasks.findIndex(s => s.index === data.index);
                                if (existing >= 0) {
                                    this.planState.researchSubtasks[existing] = { ...this.planState.researchSubtasks[existing], ...data };
                                } else {
                                    this.planState.researchSubtasks.push(data);
                                }
                                const planIdx = this.messages.findIndex(m => m.planRole === 'plan');
                                if (planIdx >= 0) {
                                    const msg = { ...this.messages[planIdx] };
                                    msg.parsedHtml = this.renderPlanCards(this.planState) + this.renderResearchCards({ topics: this.planState.researchSubtasks.map(s => s.description || s.description) });
                                    this.messages[planIdx] = msg;
                                }
                                this.scrollToBottom();
                            }
                        } else if (eventType === 'synthesis_start') {
                            this.messages = [...this.messages, {
                                id: 'streaming-synthesis-' + Date.now(),
                                role: 'assistant',
                                content: '',
                                _raw: '',
                                parsedHtml: '<div class="flex items-center gap-2 text-indigo-600 dark:text-indigo-400 p-3"><svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span class="text-sm font-medium">Synthesizing results from all specialists...</span></div>',
                                model: null,
                                isStreaming: true,
                                copied: false,
                                created_at: new Date().toISOString(),
                            }];
                            streamingIdx = this.messages.length - 1;
                            streamingContent = '';
                            this.scrollToBottom();
                        } else if (eventType === 'metadata') {
                            const data = JSON.parse(dataStr);
                            if (streamingIdx >= 0 && streamingIdx < this.messages.length) {
                                const msg = { ...this.messages[streamingIdx] };
                                let finalContent = data.full_content || streamingContent;
                                if (data.truncated) {
                                    finalContent += '\n\n---\n*Response was truncated due to length. Ask me to continue for the rest.*';
                                }
                                msg.content = finalContent;
                                msg.parsedHtml = parseMd(msg.content);
                                msg.model = data.model;
                                msg.tokens_used = data.usage?.total_tokens;
                                msg.prompt_tokens = data.usage?.prompt_tokens;
                                msg.completion_tokens = data.usage?.completion_tokens;
                                msg.isStreaming = false;
                                this.messages[streamingIdx] = msg;
                            }
                            // Plan mode persists messages server-side; skip finalizeStream
                            if (data.mode !== 'plan' && data.mode !== 'fallback') {
                                await this.finalizeStream(data, setupData, text);
                            }
                        } else if (eventType === 'persona_start') {
                            const data = JSON.parse(dataStr);
                            const p = data.persona;
                            this.loading = false;
                            if (_timer) { clearInterval(_timer); _timer = null; }
                            this.messages = [...this.messages, {
                                id: 'streaming-' + p.key + '-' + Date.now(),
                                role: 'assistant',
                                content: '',
                                _raw: '',
                                parsedHtml: '<span class="ai-chat-cursor">▌</span>',
                                persona: { name: p.name, color: p.color },
                                model: null,
                                isStreaming: true,
                                copied: false,
                                web_search_used: this.webSearchEnabled,
                                created_at: new Date().toISOString(),
                            }];
                            personaIdx[p.key] = this.messages.length - 1;
                            this.activePersonaKey = p.key;
                            this.scrollToBottom();
                        } else if (eventType === 'persona_done') {
                            // persona stream finished, metadata will finalize
                        } else if (eventType === 'persona_metadata') {
                            const data = JSON.parse(dataStr);
                            const idx = personaIdx[data.persona_key];
                            if (idx !== undefined && idx < this.messages.length) {
                                const msg = { ...this.messages[idx] };
                                msg.id = data.message_id;
                                let finalContent = data.full_content;
                                if (data.truncated) {
                                    finalContent += '\n\n---\n*Response was truncated due to length. Ask me to continue for the rest.*';
                                }
                                msg.content = finalContent;
                                delete msg._raw;
                                msg.parsedHtml = parseMd(finalContent);
                                msg.model = data.model;
                                msg.tokens_used = data.usage?.total_tokens;
                                msg.prompt_tokens = data.usage?.prompt_tokens;
                                msg.completion_tokens = data.usage?.completion_tokens;
                                msg.isStreaming = false;
                                if (data.follow_ups) msg.follow_ups = data.follow_ups;
                                this.messages[idx] = msg;
                            }
                            this.activePersonaKey = null;
                        } else if (eventType === 'persona_error') {
                            const data = JSON.parse(dataStr);
                            const idx = personaIdx[data.persona_key];
                            if (idx !== undefined && idx < this.messages.length) {
                                const msg = { ...this.messages[idx] };
                                msg.content = '⚠️ ' + (data.error || 'Error generating response');
                                msg.parsedHtml = msg.content;
                                msg.isStreaming = false;
                                this.messages[idx] = msg;
                            }
                            this.activePersonaKey = null;
                        } else if (eventType === 'done') {
                            const data = JSON.parse(dataStr);
                            if (data.updated_title) {
                                this.conversations = this.conversations.map(c => c.id === data.conversation_id ? { ...c, title: data.updated_title } : c);
                            }
                            if (data.data_freshness) this.dataFreshness = data.data_freshness;
                            this.loadConversations();
                            this.scheduleMermaidRender();
                        } else if (!eventType && dataStr) {
                            if (dataStr === '[DONE]') continue;
                            try {
                                const data = JSON.parse(dataStr);
                                if (!data.delta) continue;

                                if (data.persona_key) {
                                    const idx = personaIdx[data.persona_key];
                                    if (idx !== undefined && idx < this.messages.length) {
                                        personaRaw[data.persona_key] = (personaRaw[data.persona_key] || '') + data.delta;
                                        queueRender(idx, personaRaw[data.persona_key], data.persona_key);
                                    }
                                } else {
                                    if (streamingIdx < 0) {
                                        this.loading = false;
                                        if (_timer) { clearInterval(_timer); _timer = null; }
                                        this.messages = [...this.messages, {
                                            id: 'streaming-' + Date.now(),
                                            role: 'assistant',
                                            content: '',
                                            parsedHtml: '<span class="ai-chat-cursor">▌</span>',
                                            model: null,
                                            isStreaming: true,
                                            copied: false,
                                            web_search_used: this.webSearchEnabled,
                                            created_at: new Date().toISOString(),
                                        }];
                                        streamingIdx = this.messages.length - 1;
                                    }
                                    streamingContent += data.delta;
                                    queueRender(streamingIdx, streamingContent, null);
                                }
                            } catch (e) { /* skip unparseable chunks */ }
                        }
                    }
                }

                // Cleanup: if stream ended without metadata (connection drop)
                if (streamingIdx >= 0 && this.messages[streamingIdx]?.isStreaming) {
                    const msg = { ...this.messages[streamingIdx] };
                    msg.parsedHtml = parseMd(streamingContent);
                    msg.isStreaming = false;
                    this.messages[streamingIdx] = msg;
                }
            } catch (e) {
                if (e.name === 'AbortError') return;
                console.error('Stream error:', e);
                this.messages = [...this.messages, withHtml({ id: 'error-' + Date.now(), role: 'assistant', content: '⚠️ Network error. Please check your connection and try again.', model: null, created_at: new Date().toISOString() })];
            }
        },

        renderPlanCards(data) {
            const planText = data.plan_text || data.planText || '';
            const subtasks = data.subtasks || [];
            const statusColors = {
                pending: 'bg-gray-200 dark:bg-gray-600',
                running: 'bg-blue-400 dark:bg-blue-500 animate-pulse',
                completed: 'bg-green-500',
                failed: 'bg-red-500',
            };
            const statusLabels = {
                pending: 'Waiting...',
                running: 'Analyzing...',
                completed: 'Done',
                failed: 'Failed',
            };
            const personaColors = {
                sre: 'text-blue-600 dark:text-blue-400',
                security: 'text-red-600 dark:text-red-400',
                dba: 'text-purple-600 dark:text-purple-400',
                tech_risk: 'text-amber-600 dark:text-amber-400',
                dev_be: 'text-green-600 dark:text-green-400',
                dev_fe: 'text-cyan-600 dark:text-cyan-400',
                qa: 'text-teal-600 dark:text-teal-400',
                pm: 'text-indigo-600 dark:text-indigo-400',
                compliance: 'text-orange-600 dark:text-orange-400',
                data_analyst: 'text-pink-600 dark:text-pink-400',
                devils_advocate: 'text-rose-600 dark:text-rose-400',
                system: 'text-violet-600 dark:text-violet-400',
                ts: 'text-sky-600 dark:text-sky-400',
                pd: 'text-fuchsia-600 dark:text-fuchsia-400',
            };
            const labelColors = {
                'Pattern Analysis': 'text-violet-600 dark:text-violet-400',
                'Impact Analysis': 'text-rose-600 dark:text-rose-400',
                'Root Cause Analysis': 'text-amber-600 dark:text-amber-400',
                'Trend Analysis': 'text-cyan-600 dark:text-cyan-400',
                'Financial Analysis': 'text-red-600 dark:text-red-400',
                'Risk Assessment': 'text-orange-600 dark:text-orange-400',
                'Compliance Review': 'text-purple-600 dark:text-purple-400',
                'Comparative Analysis': 'text-teal-600 dark:text-teal-400',
                'Strength Assessment': 'text-green-600 dark:text-green-400',
                'Infrastructure Analysis': 'text-blue-600 dark:text-blue-400',
                'Security Analysis': 'text-red-600 dark:text-red-400',
                'Database Analysis': 'text-purple-600 dark:text-purple-400',
                'Data Analysis': 'text-pink-600 dark:text-pink-400',
                'Response Planning': 'text-indigo-600 dark:text-indigo-400',
            };

            let html = '<div class="plan-mode-container p-3 space-y-3">';
            if (planText) {
                html += '<p class="text-sm text-gray-700 dark:text-gray-300">' + planText + '</p>';
            }
            html += '<div class="grid gap-2">';
            subtasks.forEach((task, i) => {
                const status = task.status || 'pending';
                const colorClass = statusColors[status] || statusColors.pending;
                const label = statusLabels[status] || status;
                const pKey = task.persona_key || '';
                const personaClass = personaColors[pKey] || labelColors[task.label] || 'text-gray-600 dark:text-gray-400';
                const personaLabel = task.label || (pKey ? pKey.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'General Analysis');
                const preview = task.result_preview ? '<p class="text-[10px] text-gray-400 dark:text-gray-500 mt-1 truncate">' + task.result_preview + '</p>' : '';

                html += '<div class="flex items-start gap-2 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-700/50">';
                html += '<div class="flex-shrink-0 mt-0.5"><span class="inline-block w-2 h-2 rounded-full ' + colorClass + '"></span></div>';
                html += '<div class="flex-1 min-w-0">';
                html += '<div class="flex items-center gap-2"><span class="text-xs font-semibold ' + personaClass + '">' + personaLabel + '</span><span class="text-[10px] text-gray-400">' + label + '</span>';
                if (status === 'failed') {
                    html += '<button onclick="window.retryPlanSubtask(\'' + task.id + '\', this)" class="text-[10px] text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 underline cursor-pointer">Retry</button>';
                }
                html += '</div>';
                html += '<p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">' + task.description + '</p>';
                if (status === 'failed' && task.error_message) {
                    html += '<p class="text-[10px] text-red-400 dark:text-red-500 mt-0.5">' + task.error_message + '</p>';
                }
                html += preview;
                html += '</div></div>';
            });
            html += '</div></div>';

            return html;
        },
            let html = '<div class="p-3 space-y-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-700/50">';
            html += '<div class="flex items-center gap-2 text-blue-600 dark:text-blue-400">';
            html += '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            html += '<span class="text-sm font-semibold">A few clarifications will help me give you a better answer</span></div>';
            questions.forEach((q, i) => {
                html += '<div class="space-y-1.5">';
                html += '<label class="text-xs font-medium text-gray-700 dark:text-gray-300">' + q + '</label>';
                html += '<input type="text" x-model="clarificationAnswers[' + i + ']" ';
                html += '  @keydown.enter="submitClarification()" ';
                html += '  class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" ';
                html += '  placeholder="Your answer..." />';
                html += '</div>';
            });
            html += '<div class="flex items-center gap-2">';
            html += '<button @click="submitClarification()" class="px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50" ';
            html += '  :disabled="clarificationAnswers.some(a => !a.trim())">Submit Answers</button>';
            html += '<button @click="skipClarification()" class="px-4 py-2 text-sm font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Skip &amp; Proceed</button>';
            html += '</div></div>';
            return html;
        },

        async submitClarification() {
            if (this.clarificationAnswers.some(a => !a.trim())) return;
            const answers = this.clarificationAnswers.filter(a => a.trim());
            const { planId, conversationId } = this.clarificationState;

            const clarIdx = this.messages.findIndex(m => m.planRole === 'clarification');
            if (clarIdx >= 0) {
                const msg = { ...this.messages[clarIdx] };
                msg.parsedHtml = '<div class="p-3 text-blue-600 dark:text-blue-400 text-sm">Processing your answers...</div>';
                msg.isStreaming = true;
                this.messages[clarIdx] = msg;
            }

            this.clarificationState = null;
            this.loading = true;
            _elapsed = 0;
            _timer = setInterval(() => { _elapsed++; }, 1000);
            this.abortController = new AbortController();

            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                const res = await fetch('/admin/ai/chat/stream-plan-resume', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'text/event-stream',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: this.abortController.signal,
                    body: JSON.stringify({
                        plan_id: planId,
                        conversation_id: conversationId,
                        answers: answers,
                    }),
                });

                if (!res.ok) {
                    this.messages = [...this.messages, withHtml({ id: 'error-' + Date.now(), role: 'assistant', content: 'Failed to process clarification. Please try again.', model: null, created_at: new Date().toISOString() })];
                    this.loading = false;
                    if (_timer) { clearInterval(_timer); _timer = null; }
                    return;
                }

                await this.processResumeStream(res);
            } catch (e) {
                if (e.name === 'AbortError') return;
                console.error('Clarification resume error:', e);
                this.messages = [...this.messages, withHtml({ id: 'error-' + Date.now(), role: 'assistant', content: 'Network error during clarification resume.', model: null, created_at: new Date().toISOString() })];
            } finally {
                this.loading = false;
                if (_timer) { clearInterval(_timer); _timer = null; }
                _elapsed = 0;
            }
        },

        skipClarification() {
            this.clarificationAnswers = ['Please proceed with your best interpretation.'];
            this.submitClarification();
        },

        async processResumeStream(response) {
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let streamingIdx = -1;
            let streamingContent = '';
            let setupData = {};
            let renderQueued = false;

            const queueRender = (idx) => {
                if (renderQueued) return;
                renderQueued = true;
                requestAnimationFrame(() => {
                    if (idx >= 0 && idx < this.messages.length) {
                        const msg = { ...this.messages[idx] };
                        msg.parsedHtml = parseMd(streamingContent) + '<span class="ai-chat-cursor">&#9632;</span>';
                        this.messages[idx] = msg;
                    }
                    this.scrollToBottom();
                    renderQueued = false;
                });
            };

            try {
                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const parts = buffer.split('\n\n');
                    buffer = parts.pop() || '';

                    for (const part of parts) {
                        const lines = part.split('\n');
                        let eventType = '';
                        let dataStr = '';

                        for (const line of lines) {
                            if (line.startsWith('event: ')) eventType = line.slice(7).trim();
                            else if (line.startsWith('data: ')) dataStr += line.slice(6);
                        }

                        if (eventType === 'setup') {
                            const data = JSON.parse(dataStr);
                            setupData = data;
                        } else if (eventType === 'error') {
                            const data = JSON.parse(dataStr);
                            this.messages = [...this.messages, withHtml({ id: 'error-' + Date.now(), role: 'assistant', content: '⚠️ ' + (data.error || 'Something went wrong.'), model: null, created_at: new Date().toISOString() })];
                        } else if (eventType === 'plan_thinking') {
                            this.planState = { phase: 'thinking' };
                            this.messages = [...this.messages, {
                                id: 'plan-thinking-' + Date.now(),
                                role: 'assistant',
                                content: '',
                                parsedHtml: '<div class="flex items-center gap-2 text-purple-600 dark:text-purple-400 p-3"><svg class="w-5 h-5 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg><span class="text-sm font-medium">Thinking and planning...</span></div>',
                                isPlanMessage: true,
                                planRole: 'thinking',
                                isStreaming: true,
                                created_at: new Date().toISOString(),
                            }];
                            this.scrollToBottom();
                        } else if (eventType === 'plan_ready') {
                            const data = JSON.parse(dataStr);
                            this.planState = { phase: 'agents', planText: data.plan_text, subtasks: data.subtasks };
                            const planHtml = this.renderPlanCards(data);
                            const thinkIdx = this.messages.findIndex(m => m.planRole === 'thinking');
                            if (thinkIdx >= 0) {
                                const msg = { ...this.messages[thinkIdx] };
                                msg.parsedHtml = planHtml;
                                msg.content = data.plan_text;
                                msg.planRole = 'plan';
                                msg.isStreaming = false;
                                this.messages[thinkIdx] = msg;
                            }
                            this.scrollToBottom();
                        } else if (eventType === 'agent_status') {
                            const data = JSON.parse(dataStr);
                            if (this.planState) {
                                this.planState.subtasks[data.index] = { ...this.planState.subtasks[data.index], ...data };
                                const planIdx = this.messages.findIndex(m => m.planRole === 'plan');
                                if (planIdx >= 0) {
                                    const msg = { ...this.messages[planIdx] };
                                    msg.parsedHtml = this.renderPlanCards(this.planState);
                                    this.messages[planIdx] = msg;
                                }
                                this.scrollToBottom();
                            }
                        } else if (eventType === 'gap_analysis') {
                            const data = JSON.parse(dataStr);
                            if (data.status === 'analyzing') {
                                const planIdx = this.messages.findIndex(m => m.planRole === 'plan');
                                if (planIdx >= 0) {
                                    const msg = { ...this.messages[planIdx] };
                                    msg.parsedHtml = this.renderPlanCards(this.planState)
                                        + '<div class="mt-2 p-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50">'
                                        + '<div class="flex items-center gap-2 text-amber-600 dark:text-amber-400">'
                                        + '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>'
                                        + '<span class="text-xs font-medium">Analyzing results for completeness...</span></div></div>';
                                    this.messages[planIdx] = msg;
                                }
                            }
                            this.scrollToBottom();
                        } else if (eventType === 'research_start') {
                            const data = JSON.parse(dataStr);
                            this.researchState = { topics: data.topics, count: data.count };
                            const planIdx = this.messages.findIndex(m => m.planRole === 'plan');
                            if (planIdx >= 0) {
                                const msg = { ...this.messages[planIdx] };
                                msg.parsedHtml = this.renderPlanCards(this.planState) + this.renderResearchCards(data);
                                this.messages[planIdx] = msg;
                            }
                            this.scrollToBottom();
                        } else if (eventType === 'research_status') {
                            const data = JSON.parse(dataStr);
                            if (this.planState) {
                                if (!this.planState.researchSubtasks) this.planState.researchSubtasks = [];
                                const existing = this.planState.researchSubtasks.findIndex(s => s.index === data.index);
                                if (existing >= 0) {
                                    this.planState.researchSubtasks[existing] = { ...this.planState.researchSubtasks[existing], ...data };
                                } else {
                                    this.planState.researchSubtasks.push(data);
                                }
                                const planIdx = this.messages.findIndex(m => m.planRole === 'plan');
                                if (planIdx >= 0) {
                                    const msg = { ...this.messages[planIdx] };
                                    msg.parsedHtml = this.renderPlanCards(this.planState) + this.renderResearchCards({ topics: this.planState.researchSubtasks.map(s => s.description) });
                                    this.messages[planIdx] = msg;
                                }
                                this.scrollToBottom();
                            }
                        } else if (eventType === 'synthesis_start') {
                            this.messages = [...this.messages, {
                                id: 'streaming-synthesis-' + Date.now(),
                                role: 'assistant',
                                content: '',
                                _raw: '',
                                parsedHtml: '<div class="flex items-center gap-2 text-indigo-600 dark:text-indigo-400 p-3"><svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span class="text-sm font-medium">Synthesizing results from all specialists...</span></div>',
                                model: null,
                                isStreaming: true,
                                copied: false,
                                created_at: new Date().toISOString(),
                            }];
                            streamingIdx = this.messages.length - 1;
                            streamingContent = '';
                            this.scrollToBottom();
                        } else if (eventType === 'metadata') {
                            const data = JSON.parse(dataStr);
                            if (streamingIdx >= 0 && streamingIdx < this.messages.length) {
                                const msg = { ...this.messages[streamingIdx] };
                                msg.content = data.full_content || streamingContent;
                                msg.parsedHtml = parseMd(msg.content);
                                msg.model = data.model;
                                msg.isStreaming = false;
                                msg.id = 'msg-synthesis-' + Date.now();
                                this.messages[streamingIdx] = msg;
                            }
                        } else if (eventType === 'done') {
                            const data = JSON.parse(dataStr);
                            if (data.updated_title) {
                                const conv = this.conversations.find(c => c.id === data.conversation_id);
                                if (conv) conv.title = data.updated_title;
                            }
                            this.dataFreshness = { stats_cached: false, fetched_at: new Date().toISOString() };
                            this.loadConversations();
                        } else if (dataStr) {
                            try {
                                const parsed = JSON.parse(dataStr);
                                if (parsed.delta && streamingIdx >= 0) {
                                    streamingContent += parsed.delta;
                                    queueRender(streamingIdx);
                                }
                            } catch {}
                        }
                    }
                }
            } finally {
                if (streamingIdx >= 0 && streamingIdx < this.messages.length && this.messages[streamingIdx].isStreaming) {
                    const msg = { ...this.messages[streamingIdx] };
                    msg.content = streamingContent;
                    msg.parsedHtml = parseMd(streamingContent);
                    msg.isStreaming = false;
                    this.messages[streamingIdx] = msg;
                }
                this.loading = false;
                if (_timer) { clearInterval(_timer); _timer = null; }
            }
        },

        renderResearchCards(data) {
            const topics = data.topics || [];
            if (topics.length === 0) return '';
            let html = '<div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">';
            html += '<div class="flex items-center gap-2 mb-2">';
            html += '<svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>';
            html += '<span class="text-xs font-semibold text-amber-600 dark:text-amber-400">Deep Research</span></div>';
            html += '<div class="grid gap-1.5">';
            topics.forEach((topic, i) => {
                const topicText = typeof topic === 'string' ? topic : (topic.description || topic.topic || '');
                html += '<div class="flex items-center gap-2 p-1.5 rounded bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/30">';
                html += '<span class="inline-block w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>';
                html += '<span class="text-xs text-amber-700 dark:text-amber-300">' + topicText + '</span>';
                html += '</div>';
            });
            html += '</div></div>';
            return html;
        },

        async finalizeStream(metadata, setupData, firstMessage) {
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                const res = await fetch('/admin/ai/chat/finalize', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        conversation_id: setupData?.conversation_id || this.activeConversationId,
                        content: metadata.full_content,
                        model: metadata.model,
                        prompt_tokens: metadata.usage?.prompt_tokens,
                        completion_tokens: metadata.usage?.completion_tokens,
                        total_tokens: metadata.usage?.total_tokens,
                        response_time_ms: metadata.response_time_ms,
                        is_new: setupData?.is_new,
                        first_message: setupData?.is_new ? firstMessage : undefined,
                    }),
                });

                if (res.ok) {
                    const data = await res.json();
                    if (data.success) {
                        const lastIdx = this.messages.length - 1;
                        if (lastIdx >= 0 && this.messages[lastIdx].role === 'assistant') {
                            const msg = { ...this.messages[lastIdx] };
                            msg.id = data.assistant_message?.id || msg.id;
                            msg.content = data.assistant_message?.content || msg.content;
                            msg.parsedHtml = parseMd(msg.content);
                            if (data.follow_up_questions) msg.follow_ups = data.follow_up_questions;
                            this.messages[lastIdx] = msg;
                        }
                        if (data.updated_title) {
                            this.conversations = this.conversations.map(c =>
                                c.id === data.conversation_id ? { ...c, title: data.updated_title } : c
                            );
                        }
                        this.loadConversations();
                        this.scheduleMermaidRender();
                    }
                }
            } catch (e) {
                console.error('Finalize error:', e);
            }
        },

        stopGeneration() {
            if (this.abortController) {
                this.abortController.abort();
                this.abortController = null;
            }
            this.loading = false;
            if (_timer) { clearInterval(_timer); _timer = null; }
            _elapsed = 0;
        },

        copyMessage(content) {
            navigator.clipboard.writeText(content).then(() => {
                this.messages = this.messages.map(m =>
                    m.content === content ? { ...m, copied: true } : m
                );
                setTimeout(() => {
                    this.messages = this.messages.map(m =>
                        m.copied ? { ...m, copied: false } : m
                    );
                }, 1500);
            });
        },

        async regenerateResponse(assistantIdx) {
            let userMsgIdx = assistantIdx - 1;
            if (userMsgIdx < 0 || this.messages[userMsgIdx]?.role !== 'user') return;

            const userText = this.messages[userMsgIdx].content;
            this.messages = this.messages.filter((_, i) => i !== assistantIdx);
            this.inputText = userText;
            this.sendMessage();
        },

        async submitFeedback(messageId, feedback) {
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                const res = await fetch('/admin/ai/chat/messages/' + messageId + '/feedback', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ feedback }),
                });
                if (res.ok) {
                    this.messages = this.messages.map(m =>
                        m.id === messageId ? { ...m, feedback } : m
                    );
                }
            } catch (e) { console.error(e); }
        },

        async searchIncidents() {
            if (this.incidentSearch.length < 2) {
                this.incidentResults = [];
                return;
            }
            this.incidentSearchLoading = true;
            try {
                const params = new URLSearchParams({ q: this.incidentSearch });
                const res = await fetch('/admin/ai/chat/incident-search?' + params.toString(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) {
                    const data = await res.json();
                    this.incidentResults = data.incidents || [];
                }
            } catch (e) { console.error(e); }
            this.incidentSearchLoading = false;
        },

        toggleIncidentRef(inc) {
            const exists = this.referencedIncidents.some(i => i.no === inc.no);
            if (exists) {
                this.referencedIncidents = this.referencedIncidents.filter(i => i.no !== inc.no);
            } else {
                this.referencedIncidents = [...this.referencedIncidents, inc];
            }
        },

        removeReferencedIncident(idx) {
            this.referencedIncidents = this.referencedIncidents.filter((_, i) => i !== idx);
        },

        isIncidentReferenced(no) {
            return this.referencedIncidents.some(i => i.no === no);
        },

        async restoreReferencedIncidents() {
            // Extract incident IDs from all user messages in this conversation
            const idPattern = /\d{8}_(?:IN|IS)_\d{4}/g;
            const foundIds = new Set();
            for (const msg of this.messages) {
                if (msg.role === 'user') {
                    const matches = msg.content.matchAll(idPattern);
                    for (const m of matches) foundIds.add(m[0]);
                }
            }

            if (foundIds.size === 0) {
                this.referencedIncidents = [];
                return;
            }

            // Look up incident details for each ID from the backend
            const refs = [];
            for (const no of foundIds) {
                if (refs.some(i => i.no === no)) continue;

                let found = null;
                for (const msg of this.messages) {
                    if (msg.role === 'assistant' && msg.content && msg.content.includes(no)) {
                        const sevMatch = msg.content.match(new RegExp(no + '[\\s\\S]*?Severity[:\\s]*(P[1-4]|G|X[1-4])', 'i'));
                        found = { no, title: '', severity: sevMatch ? sevMatch[1].toUpperCase() : '', status: '', date: '', pic: '' };
                        break;
                    }
                }

                if (!found) {
                    found = { no, title: '', severity: '', status: '', date: '', pic: '' };
                }
                refs.push(found);
            }
            this.referencedIncidents = refs;

            // Fetch fresh details from API for any incidents missing titles
            const missingTitles = this.referencedIncidents.filter(i => !i.title);
            if (missingTitles.length > 0) {
                try {
                    const ids = missingTitles.map(i => i.no);
                    const params = new URLSearchParams({ q: ids.join(' ') });
                    const res = await fetch('/admin/ai/chat/incident-search?' + params.toString(), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (res.ok) {
                        const data = await res.json();
                        this.referencedIncidents = this.referencedIncidents.map(ref => {
                            const inc = (data.incidents || []).find(i => i.no === ref.no);
                            if (!inc) return ref;
                            return {
                                ...ref,
                                title: inc.title,
                                severity: ref.severity || inc.severity,
                                status: inc.status,
                                date: inc.date,
                                pic: inc.pic,
                            };
                        });
                    }
                } catch (e) { /* non-critical, chips just won't have full details */ }
            }
        },

        scheduleMermaidRender() {
            requestAnimationFrame(() => {
                this.$nextTick(() => this.renderMermaidDiagrams());
            });
        },

        async renderMermaidDiagrams() {
            const container = this.$refs.messageContainer;
            if (!container || typeof mermaid === 'undefined') return;

            const blocks = container.querySelectorAll('code.language-mermaid');
            for (const block of blocks) {
                const pre = block.closest('pre');
                if (!pre || pre.dataset.mermaidRendered) continue;
                pre.dataset.mermaidRendered = 'true';

                try {
                    const rawText = block.textContent.trim();
                    if (!rawText) continue;
                    const id = 'mermaid-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6);
                    const { svg } = await mermaid.render(id, rawText);
                    pre.innerHTML = svg;
                    pre.className = 'mermaid';
                } catch (e) {
                    // Show diagram source as a code block instead of the error message
                    const source = block.textContent || '';
                    pre.innerHTML = '<div class="text-[10px] text-gray-400 dark:text-gray-500 mb-1 italic">Diagram preview unavailable</div><pre class="text-xs bg-gray-50 dark:bg-gray-800 rounded p-2 overflow-x-auto"><code>' + source.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</code></pre>';
                    pre.dataset.mermaidRendered = 'error';
                }
            }
        },

        scrollToBottom() {
            const el = this.$refs.messageContainer;
            if (el) el.scrollTop = el.scrollHeight;
        },

        autoResize() {
            const el = this.$refs.chatInput;
            if (el) {
                el.style.height = 'auto';
                el.style.height = Math.min(el.scrollHeight, 200) + 'px';
            }
        },
    };
}

window.retryPlanSubtask = async function(subtaskId, btn) {
    btn.textContent = 'Retrying...';
    btn.disabled = true;
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch('/admin/ai/chat/plan-subtask/' + subtaskId + '/retry', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        });
        if (res.ok) {
            const card = btn.closest('.flex.items-start');
            const dot = card?.querySelector('.rounded-full');
            if (dot) dot.className = 'inline-block w-2 h-2 rounded-full bg-blue-400 dark:bg-blue-500 animate-pulse';
            const statusSpan = btn.previousElementSibling;
            if (statusSpan) statusSpan.textContent = 'Analyzing...';
            btn.remove();
        } else {
            const data = await res.json().catch(() => ({}));
            btn.textContent = 'Retry';
            btn.disabled = false;
            console.error('Retry failed:', res.status, data);
        }
    } catch (e) {
        btn.textContent = 'Retry';
        btn.disabled = false;
        console.error('Retry error:', e);
    }
};
</script>
</x-filament-panels::page>
