package tools

import (
	"encoding/json"
	"fmt"
	"sync"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
	"techrisk-dashboard/dingtalk-agent/internal/watch"
)

// ToolHandler is a function that executes a tool with parsed JSON arguments.
type ToolHandler func(argsJSON string) (string, error)

// Tool represents a registered agent tool.
type Tool struct {
	Definition llm.ToolDefinition
	Handler    ToolHandler
	// ResultCapBytes caps this tool's result before it enters LLM context;
	// 0 = the agent loop's default (MaxToolResultBytes).
	ResultCapBytes int
}

// Registry manages the set of available tools and dispatches execution.
type Registry struct {
	mu           sync.RWMutex
	tools        map[string]Tool
	notion       *notionTools      // nil when the Notion tools are disabled
	dash         *dashboardClient  // nil without cfg — the watch poller sweeps through it
	pending      *schedule.Pending // parked reminder requests; nil without cfg
	watchPending *watch.Pending    // parked watch requests; nil without cfg
}

// NewRegistry initializes the registry with the dashboard API tools, plus the
// reminder + incident-watch tools, the web research tools when enabled
// (AGENT_WEB_TOOLS=0 to revoke) and the read-only Notion doc tools when
// enabled (AGENT_NOTION_TOOLS=0 to revoke).
func NewRegistry(cfg *config.Config) *Registry {
	r := &Registry{
		tools: make(map[string]Tool),
	}
	if cfg != nil {
		r.registerDashboardTools(cfg)
		r.registerGenerateTools(cfg)
		r.registerScheduleTools()
		r.registerWatchTools()
		if cfg.WebToolsEnabled {
			r.registerWebTools()
		}
		if cfg.NotionToolsEnabled {
			r.registerNotionTools(cfg)
		}
	}
	return r
}

// Has reports whether a tool with that name is registered.
func (r *Registry) Has(name string) bool {
	r.mu.RLock()
	defer r.mu.RUnlock()
	_, ok := r.tools[name]
	return ok
}

// Register adds a tool to the registry (default result cap).
func (r *Registry) Register(def llm.ToolDefinition, handler ToolHandler) {
	r.RegisterCapped(def, handler, 0)
}

// RegisterCapped adds a tool with a per-tool result cap in bytes (0 = default).
func (r *Registry) RegisterCapped(def llm.ToolDefinition, handler ToolHandler, capBytes int) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.tools[def.Function.Name] = Tool{
		Definition:     def,
		Handler:        handler,
		ResultCapBytes: capBytes,
	}
}

// ResultCap returns the tool's result cap in bytes (0 = default, also when absent).
func (r *Registry) ResultCap(name string) int {
	r.mu.RLock()
	defer r.mu.RUnlock()
	return r.tools[name].ResultCapBytes
}

// GetDefinitions returns the OpenAI tool definitions for all registered tools.
func (r *Registry) GetDefinitions() []llm.ToolDefinition {
	r.mu.RLock()
	defer r.mu.RUnlock()

	defs := make([]llm.ToolDefinition, 0, len(r.tools))
	for _, t := range r.tools {
		defs = append(defs, t.Definition)
	}
	return defs
}

// Execute runs the named tool with the given JSON arguments.
func (r *Registry) Execute(name, argsJSON string) (string, error) {
	r.mu.RLock()
	tool, exists := r.tools[name]
	r.mu.RUnlock()

	if !exists {
		return "", fmt.Errorf("unknown tool: %s", name)
	}

	if argsJSON == "" {
		argsJSON = "{}"
	}

	result, err := tool.Handler(argsJSON)
	if err != nil {
		return fmt.Sprintf("Error executing %s: %v", name, err), nil
	}
	return result, nil
}

// Helper to marshal JSON schema map
func schema(properties map[string]any, required []string) any {
	s := map[string]any{
		"type":       "object",
		"properties": properties,
	}
	if len(required) > 0 {
		s["required"] = required
	}
	return s
}

// rawSchema decodes a static JSON schema literal into a value that marshals as
// a JSON OBJECT. Parameters must never be a raw string — json.Marshal
// double-encodes it and the upstream 400s the WHOLE request
// ("tools[N].function.parameters is invalid or missing", prod 2026-11-10).
func rawSchema(s string) any {
	var v any
	if err := json.Unmarshal([]byte(s), &v); err != nil {
		panic("rawSchema: invalid literal: " + err.Error()) // static input; fail at boot
	}
	return v
}
