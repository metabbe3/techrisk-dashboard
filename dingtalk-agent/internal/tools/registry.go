package tools

import (
	"fmt"
	"sync"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// ToolHandler is a function that executes a tool with parsed JSON arguments.
type ToolHandler func(argsJSON string) (string, error)

// Tool represents a registered agent tool.
type Tool struct {
	Definition llm.ToolDefinition
	Handler    ToolHandler
}

// Registry manages the set of available tools and dispatches execution.
type Registry struct {
	mu    sync.RWMutex
	tools map[string]Tool
}

// NewRegistry initializes the registry with the dashboard API tools, plus the
// web research tools when enabled (AGENT_WEB_TOOLS=0 to revoke).
func NewRegistry(cfg *config.Config) *Registry {
	r := &Registry{
		tools: make(map[string]Tool),
	}
	if cfg != nil {
		r.registerDashboardTools(cfg)
		if cfg.WebToolsEnabled {
			r.registerWebTools()
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

// Register adds a tool to the registry.
func (r *Registry) Register(def llm.ToolDefinition, handler ToolHandler) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.tools[def.Function.Name] = Tool{
		Definition: def,
		Handler:    handler,
	}
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
