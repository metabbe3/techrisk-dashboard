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
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                    TechRisk AI
                    <span class="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,.5)]" :class="loading ? 'animate-pulse' : ''"></span>
                </h2>
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
            {{-- Compact "..." Menu --}}
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
                                <p class="text-sm" x-text="msg.content"></p>
                                <p class="ai-chat-msg-time" x-text="new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})"></p>
                            </div>
                        </template>
                        <template x-if="msg.role === 'assistant'">
                            <div>
                                <template x-if="msg.persona">
                                    <div class="flex items-center gap-1.5 mb-1.5">
                                        <span class="text-[11px] font-semibold" :style="'color:' + getPersonaColor(msg.persona.color)" x-text="msg.persona.name"></span>
                                        <span class="text-[10px] text-gray-400">perspective</span>
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
                <template x-if="selectedPersonas.length > 0">
                    <div class="flex flex-col gap-2 w-full">
                        <template x-for="roleKey in selectedPersonas" :key="roleKey">
                            <div class="flex items-center gap-2">
                                <span class="ai-chat-persona-icon" :style="'width:28px;height:28px;font-size:10px;background:' + getPersonaColor(getAgentByKey(roleKey)?.color || 'gray', 0.15) + '; color:' + getPersonaColor(getAgentByKey(roleKey)?.color || 'gray')">
                                    <span x-text="getAgentInitial(getAgentByKey(roleKey)?.display_name || '?')"></span>
                                </span>
                                <div class="ai-chat-typing"><span></span><span></span><span></span></div>
                                <span class="text-[11px] text-gray-400" x-text="getAgentByKey(roleKey)?.display_name || roleKey"></span>
                            </div>
                        </template>
                        <div class="flex items-center gap-2 ml-9">
                            <span class="text-xs text-gray-400" x-ref="elapsedDisplay" x-show="loading"></span>
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
                                <span class="text-xs text-gray-400" x-ref="elapsedDisplay2" x-show="loading"></span>
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

                {{-- Export PDF --}}
                <a :href="activeConversationId ? '/admin/ai/chat/conversations/' + activeConversationId + '/export-pdf' : '#'"
                   :class="{ 'opacity-40 pointer-events-none': !activeConversationId || messages.length === 0 }"
                   target="_blank"
                   class="ai-chat-attach-btn"
                   title="Export conversation to PDF">
                    <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </a>
                <button @click="sendMessage()" :disabled="loading || !inputText.trim()" class="ai-chat-send-btn">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                </button>
            </div>
            <p class="text-[10px] text-gray-400 text-center mt-1.5">AI can produce inaccurate information. Always verify important data. <span x-text="'Model: ' + selectedModelLabel"></span></p>
            <p x-show="selectedPersonas.length >= 3" class="text-[10px] text-amber-500 text-center mt-0.5" x-text="selectedPersonas.length + ' personas will generate separate responses, increasing token usage.'"></p>
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
                    this.messages = data.messages.map(m => withHtml(m));
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
                if (_timer) { clearInterval(_timer); _timer = null; }
                _elapsed = 0;
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
            this.messages = [...this.messages, userMsg];
            this.inputText = '';
            this.autoResize();
            this.scrollToBottom();

            this.loading = true;
            _elapsed = 0;
            this.lastUserMessage = text;
            _timer = setInterval(() => {
                _elapsed++;
                const el = document.querySelector('[x-ref="elapsedDisplay"]') || document.querySelector('[x-ref="elapsedDisplay2"]');
                if (el) el.textContent = _elapsed + 's';
            }, 1000);
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
                        personas: this.selectedPersonas.length > 0 ? this.selectedPersonas : undefined,
                        web_search: this.webSearchEnabled,
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
                    this.messages = [...this.messages, withHtml({
                        id: 'error-' + Date.now(),
                        role: 'assistant',
                        content: '⚠️ Server returned an invalid response. Please try again.',
                        model: null,
                        created_at: new Date().toISOString(),
                    })];
                    return;
                }

                if (data.success) {
                    const searched = data.web_search_used === true;
                    // Handle multi-persona responses
                    if (data.mode === 'personas' && data.assistant_messages) {
                        for (const msg of data.assistant_messages) {
                            this.messages = [...this.messages, withHtml({
                                ...msg,
                                web_search_used: searched,
                                copied: false,
                            })];
                        }
                    } else if (data.assistant_message) {
                        // Default single response (backward compatible)
                        this.messages = [...this.messages, withHtml({
                            ...data.assistant_message,
                            web_search_used: searched,
                            copied: false,
                        })];
                    }

                    // Defer all side effects so Alpine processes the message change cleanly
                    const payload = data;
                    setTimeout(() => {
                        if (!this.activeConversationId && payload.conversation_id) {
                            this.activeConversationId = payload.conversation_id;
                        }
                        if (payload.updated_title) {
                            this.conversations = this.conversations.map(c =>
                                c.id === payload.conversation_id ? { ...c, title: payload.updated_title } : c
                            );
                        }
                        if (payload.data_freshness) {
                            this.dataFreshness = payload.data_freshness;
                        }
                        this.scheduleMermaidRender();
                        this.scrollToBottom();
                        this.loadConversations();
                    }, 50);
                } else {
                    this.messages = [...this.messages, withHtml({
                        id: 'error-' + Date.now(),
                        role: 'assistant',
                        content: '⚠️ ' + (data.error || 'Something went wrong. Please try again.'),
                        model: null,
                        created_at: new Date().toISOString(),
                    })];
                }
            } catch (e) {
                if (e.name === 'AbortError') {
                    return;
                }
                console.error('sendMessage error:', e);
                this.messages = [...this.messages, withHtml({
                    id: 'error-' + Date.now(),
                    role: 'assistant',
                    content: '⚠️ Network error. Please check your connection and try again.',
                    model: null,
                    created_at: new Date().toISOString(),
                })];
            } finally {
                this.loading = false;
                if (_timer) { clearInterval(_timer); _timer = null; }
                _elapsed = 0;
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
            if (_timer) { clearInterval(_timer); _timer = null; }
            _elapsed = 0;
        },

        copyMessage(content) {
            navigator.clipboard.writeText(content).then(() => {
                const idx = this.messages.map(m => m.role).lastIndexOf('assistant');
                if (idx !== -1) {
                    this.messages = this.messages.map((m, i) =>
                        i === idx ? { ...m, copied: true } : m
                    );
                    setTimeout(() => {
                        this.messages = this.messages.map(m =>
                            m.copied ? { ...m, copied: false } : m
                        );
                    }, 1500);
                }
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
                    const id = 'mermaid-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6);
                    const { svg } = await mermaid.render(id, block.textContent);
                    pre.innerHTML = svg;
                    pre.className = 'mermaid';
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
