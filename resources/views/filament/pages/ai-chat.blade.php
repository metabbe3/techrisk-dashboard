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
            <template x-for="conv in conversations" :key="conv.id">
                <div @click="selectConversation(conv.id)"
                     class="ai-chat-sidebar-item"
                     :class="{ 'active': activeConversationId === conv.id }">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate" x-text="conv.title || 'New Chat'"></p>
                        <p class="text-xs text-gray-400 truncate mt-0.5" x-text="conv.last_message"></p>
                    </div>
                    <button @click.stop="deleteConversation(conv.id)" class="ai-chat-delete-btn" title="Delete">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </div>
            </template>
            <div x-show="conversations.length === 0" class="p-4 text-center text-xs text-gray-400">
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
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-200">TechRisk AI</h2>
                <div class="flex items-center gap-2">
                    <p class="text-xs text-gray-400">Ask anything about your incidents, patterns, trends, and data</p>
                    <span class="ai-chat-freshness" x-show="dataFreshness">
                        <span x-text="dataFreshnessLabel"></span>
                        <button @click="refreshContext()" class="ai-chat-refresh-btn" title="Refresh data">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </button>
                    </span>
                </div>
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
        </div>

        {{-- Messages --}}
        <div class="ai-chat-messages" x-ref="messageContainer">
            {{-- Empty State --}}
            <div x-show="messages.length === 0 && !loading" class="ai-chat-empty">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-2">TechRisk AI</h3>
                <p class="text-sm text-gray-400 max-w-md">Your intelligent risk & incident analyst. Ask about incidents, patterns, trends, root causes, or financial impact.</p>
                <div class="flex flex-wrap gap-2 mt-6 justify-center max-w-lg">
                    <button @click="inputText = '/summary this month'; sendMessage()" class="ai-chat-suggestion">/summary this month</button>
                    <button @click="inputText = '/risk'; sendMessage()" class="ai-chat-suggestion">/risk</button>
                    <button @click="inputText = '/search '; $refs.input.focus()" class="ai-chat-suggestion">/search the web</button>
                    <button @click="inputText = 'What patterns do you see in P1 and P2 incidents?'; sendMessage()" class="ai-chat-suggestion">Analyze severity patterns</button>
                </div>
            </div>

            {{-- Message List --}}
            <template x-for="(msg, idx) in messages" :key="msg.id">
                <div class="ai-chat-msg" :class="msg.role">
                    <div class="ai-chat-msg-avatar" :class="msg.role">
                        <template x-if="msg.role === 'user'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </template>
                        <template x-if="msg.role === 'assistant'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
                        </template>
                    </div>
                    <div class="ai-chat-msg-content" :class="msg.role">
                        <template x-if="msg.role === 'user'">
                            <p class="text-sm" x-text="msg.content"></p>
                        </template>
                        <template x-if="msg.role === 'assistant'">
                            <div>
                                <div class="ai-chat-msg-text text-sm prose prose-sm dark:prose-invert max-w-none" x-html="renderMarkdown(msg.content)"></div>
                                <div class="flex items-center gap-2 mt-2">
                                    <span x-show="msg.model" class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400" x-text="msg.model"></span>
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
                                    <span x-show="msg.copied" class="text-[10px] text-green-500">Copied!</span>
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
                <div class="ai-chat-msg-avatar assistant">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
                </div>
                <div class="ai-chat-msg-content assistant">
                    <div class="flex items-center gap-2">
                        <div class="ai-chat-typing">
                            <span></span><span></span><span></span>
                        </div>
                        <span class="text-xs text-gray-400" x-show="elapsed > 0" x-text="elapsed + 's'"></span>
                        <button @click="stopGeneration()" class="ai-chat-stop-btn">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
                            Stop
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Input --}}
        <div class="ai-chat-input-area">
            {{-- Referenced incidents chips --}}
            <div x-show="referencedIncidents.length > 0" x-transition class="ai-chat-ref-bar">
                <template x-for="(inc, idx) in referencedIncidents" :key="inc.no">
                    <span class="ai-chat-ref-chip">
                        <span class="ai-chat-ref-severity" :class="'severity-' + (inc.severity || '').toLowerCase().replace(' ', '')" x-text="inc.severity"></span>
                        <span class="ai-chat-ref-text" x-text="inc.no + (inc.title ? ': ' + inc.title.substring(0, 40) : '')"></span>
                        <button @click="removeReferencedIncident(idx)" class="ai-chat-ref-remove">&times;</button>
                    </span>
                </template>
                <button @click="referencedIncidents = []" class="ai-chat-ref-clear">Clear all</button>
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
                          x-ref="chatInput"
                          rows="1"
                          placeholder="Ask about incidents, patterns, trends... or type / for commands"
                          class="ai-chat-textarea"
                          :disabled="loading"
                          @input="onInput()"></textarea>

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
                                        <p class="text-[11px] text-gray-400 truncate" x-text="inc.title"></p>
                                    </div>
                                    <span class="text-[10px] text-gray-400" x-text="inc.date"></span>
                                    <span x-show="isIncidentReferenced(inc.no)" class="text-green-500 text-xs">&#10003;</span>
                                </button>
                            </template>
                            <div x-show="incidentResults.length === 0 && incidentSearch.length >= 2 && !incidentSearchLoading" class="p-3 text-center text-xs text-gray-400">No incidents found</div>
                            <div x-show="incidentSearchLoading" class="p-3 text-center text-xs text-gray-400">Searching...</div>
                            <div x-show="incidentSearch.length < 2" class="p-3 text-center text-xs text-gray-400">Type at least 2 characters to search</div>
                        </div>
                    </div>
                </div>

                <button @click="sendMessage()" :disabled="loading || !inputText.trim()" class="ai-chat-send-btn">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                </button>
            </div>
            <p class="text-[10px] text-gray-400 text-center mt-1.5">AI can make mistakes. Verify important data. <span x-text="'Model: ' + selectedModelLabel"></span></p>
        </div>
    </div>
</div>

<script>
function aiChat() {
    const slashCommands = {{ Js::from(config('ai.chat_slash_commands', [])) }};

    return {
        conversations: [],
        activeConversationId: null,
        messages: [],
        inputText: '',
        loading: false,
        elapsed: 0,
        timer: null,
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

        get selectedModelLabel() {
            return this.models[this.selectedModel] || this.selectedModel;
        },

        get dataFreshnessLabel() {
            if (!this.dataFreshness) return '';
            if (this.dataFreshness.stats_cached) return 'Data: cached';
            return 'Data: fresh';
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
            if (window.innerWidth >= 1024) this.showSidebar = true;

            mermaid.initialize({
                startOnLoad: false,
                theme: document.documentElement.classList.contains('dark') ? 'dark' : 'default',
            });

            document.addEventListener('livewire:navigating', () => {
                if (this.timer) clearInterval(this.timer);
            });
        },

        onInput() {
            this.autoResize();
            this.slashActive = this.inputText.startsWith('/') && this.filteredCommands.length > 0;
            this.slashIndex = 0;
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
                    this.messages = data.messages;
                    if (data.conversation?.model) this.selectedModel = data.conversation.model;
                    this.restoreReferencedIncidents();
                    this.$nextTick(() => this.scrollToBottom());
                }
            } catch (e) { console.error(e); }
            if (window.innerWidth < 1024) this.showSidebar = false;
        },

        async newConversation() {
            this.activeConversationId = null;
            this.messages = [];
            this.referencedIncidents = [];
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

        async sendMessage() {
            const text = this.inputText.trim();
            if (!text) return;

            // Safety: reset stuck loading state
            if (this.loading) {
                this.loading = false;
                if (this.timer) { clearInterval(this.timer); this.timer = null; }
                this.elapsed = 0;
            }

            this.slashActive = false;

            // Include referenced incident IDs in message display
            const refIds = this.referencedIncidents.map(i => i.no);
            const refPrefix = refIds.length > 0 ? refIds.map(no => `[${no}]`).join(' ') + '\n' : '';

            const userMsg = {
                id: 'temp-' + Date.now(),
                role: 'user',
                content: refPrefix + text,
                created_at: new Date().toISOString(),
            };
            this.messages.push(userMsg);
            this.inputText = '';
            this.autoResize();
            this.scrollToBottom();

            this.loading = true;
            this.elapsed = 0;
            this.lastUserMessage = text;
            this.timer = setInterval(() => this.elapsed++, 1000);
            this.abortController = new AbortController();

            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                const res = await fetch('/admin/ai/chat/send', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: this.abortController.signal,
                    body: JSON.stringify({
                        message: text,
                        conversation_id: this.activeConversationId,
                        model: this.selectedModel,
                        referenced_incidents: refIds,
                    }),
                });

                if (res.status === 419) {
                    window.location.reload();
                    return;
                }

                // Safely parse JSON — handle non-JSON responses (500 HTML, proxy errors)
                let data;
                try {
                    const responseText = await res.text();
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Failed to parse response:', parseError);
                    this.messages.push({
                        id: 'error-' + Date.now(),
                        role: 'assistant',
                        content: '⚠️ Server returned an invalid response. Please try again.',
                        model: null,
                        created_at: new Date().toISOString(),
                    });
                    return;
                }

                if (data.success && data.assistant_message) {
                    // Update temp user message with real ID
                    if (data.user_message) {
                        const idx = this.messages.findIndex(m => m.id === userMsg.id);
                        if (idx !== -1) this.messages[idx].id = data.user_message.id;
                    }

                    // Push assistant message and force Alpine to render
                    this.messages.push({
                        ...data.assistant_message,
                        copied: false,
                    });
                    await this.$nextTick();

                    if (!this.activeConversationId && data.conversation_id) {
                        this.activeConversationId = data.conversation_id;
                    }

                    if (data.updated_title) {
                        const conv = this.conversations.find(c => c.id === data.conversation_id);
                        if (conv) conv.title = data.updated_title;
                    }

                    if (data.data_freshness) {
                        this.dataFreshness = data.data_freshness;
                    }

                    // Fire and forget — don't block rendering
                    this.loadConversations();
                } else {
                    this.messages.push({
                        id: 'error-' + Date.now(),
                        role: 'assistant',
                        content: '⚠️ ' + (data.error || 'Something went wrong. Please try again.'),
                        model: null,
                        created_at: new Date().toISOString(),
                    });
                }
            } catch (e) {
                if (e.name === 'AbortError') {
                    return;
                }
                console.error('sendMessage error:', e);
                this.messages.push({
                    id: 'error-' + Date.now(),
                    role: 'assistant',
                    content: '⚠️ Network error. Please check your connection and try again.',
                    model: null,
                    created_at: new Date().toISOString(),
                });
            } finally {
                this.loading = false;
                if (this.timer) { clearInterval(this.timer); this.timer = null; }
                this.elapsed = 0;
                this.showIncidentPicker = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        stopGeneration() {
            if (this.abortController) {
                this.abortController.abort();
                this.abortController = null;
            }
            this.loading = false;
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
            this.elapsed = 0;
        },

        copyMessage(content) {
            navigator.clipboard.writeText(content).then(() => {
                // Flash "Copied!" on the last assistant message
                const msgs = this.messages;
                for (let i = msgs.length - 1; i >= 0; i--) {
                    if (msgs[i].role === 'assistant') {
                        msgs[i].copied = true;
                        setTimeout(() => { msgs[i].copied = false; }, 1500);
                        break;
                    }
                }
            });
        },

        async regenerateResponse(assistantIdx) {
            // Find the user message before this assistant message
            let userMsgIdx = assistantIdx - 1;
            if (userMsgIdx < 0 || this.messages[userMsgIdx]?.role !== 'user') return;

            const userText = this.messages[userMsgIdx].content;

            // Remove the last assistant message
            this.messages.splice(assistantIdx, 1);

            // Re-send the user message
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
                    const msg = this.messages.find(m => m.id === messageId);
                    if (msg) msg.feedback = feedback;
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
            const idx = this.referencedIncidents.findIndex(i => i.no === inc.no);
            if (idx !== -1) {
                this.referencedIncidents.splice(idx, 1);
            } else {
                this.referencedIncidents.push(inc);
            }
        },

        removeReferencedIncident(idx) {
            this.referencedIncidents.splice(idx, 1);
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
            this.referencedIncidents = [];
            for (const no of foundIds) {
                // Check if already in the list
                if (this.referencedIncidents.some(i => i.no === no)) continue;

                // Try to find details from existing assistant messages first (free)
                let found = null;
                for (const msg of this.messages) {
                    if (msg.role === 'assistant' && msg.content && msg.content.includes(no)) {
                        // Extract severity from assistant messages that mention this incident
                        const sevMatch = msg.content.match(new RegExp(no + '[\\s\\S]*?Severity[:\\s]*(P[1-4]|G|X[1-4])', 'i'));
                        found = { no, title: '', severity: sevMatch ? sevMatch[1].toUpperCase() : '', status: '', date: '', pic: '' };
                        break;
                    }
                }

                if (!found) {
                    found = { no, title: '', severity: '', status: '', date: '', pic: '' };
                }
                this.referencedIncidents.push(found);
            }

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
                        for (const inc of (data.incidents || [])) {
                            const ref = this.referencedIncidents.find(i => i.no === inc.no);
                            if (ref) {
                                ref.title = inc.title;
                                ref.severity = ref.severity || inc.severity;
                                ref.status = inc.status;
                                ref.date = inc.date;
                                ref.pic = inc.pic;
                            }
                        }
                    }
                } catch (e) { /* non-critical, chips just won't have full details */ }
            }
        },

        renderMarkdown(text) {
            if (!text) return '';
            try {
                let html = marked.parse(text, { breaks: true, gfm: true });
                // Schedule mermaid rendering after DOM update
                this.$nextTick(() => this.renderMermaidDiagrams());
                return html;
            } catch {
                return text.replace(/\n/g, '<br>');
            }
        },

        async renderMermaidDiagrams() {
            const container = this.$refs.messageContainer;
            if (!container) return;

            const blocks = container.querySelectorAll('code.language-mermaid');
            for (const block of blocks) {
                const pre = block.closest('pre');
                if (!pre || pre.dataset.mermaidRendered) continue;

                try {
                    const id = 'mermaid-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6);
                    const { svg } = await mermaid.render(id, block.textContent);
                    const wrapper = document.createElement('div');
                    wrapper.className = 'mermaid';
                    wrapper.innerHTML = svg;
                    pre.replaceWith(wrapper);
                } catch (e) {
                    pre.dataset.mermaidRendered = 'error';
                    console.warn('Mermaid render error:', e);
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
</script>
</x-filament-panels::page>
