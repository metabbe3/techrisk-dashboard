package tools

import (
	"bytes"
	"context"
	"log"
	"net/http"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// TestExecuteRecoversFromPanickingTool pins the panic guard: a panicking
// handler must surface as error CONTENT (like any handler error) and LOG a
// stack trace — the loop's per-goroutine recover (loop.go execTools) never
// fires anymore because Execute recovers first, so this is the only place the
// stack lands in the operator log. Also defense for non-loop callers (tests,
// future seams).
func TestExecuteRecoversFromPanickingTool(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		t.Error("no dashboard call expected")
	})
	reg.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "panicking_tool",
			Description: "test-only tool whose handler panics",
			Parameters:  schema(map[string]any{}, nil),
		},
	}, func(ctx context.Context, argsJSON string) (string, error) {
		panic("boom — handler blew up")
	})

	var logs bytes.Buffer
	log.SetOutput(&logs)
	t.Cleanup(func() { log.SetOutput(log.Default().Writer()) })

	out, err := reg.Execute(context.Background(), "panicking_tool", "{}")
	if err != nil {
		t.Fatalf("recovered panic must surface as content, not a Go error: %v", err)
	}
	if !strings.Contains(out, "internal panic") || !strings.Contains(out, "boom") {
		t.Errorf("expected panic content in result, got %.120s", out)
	}
	if !strings.Contains(logs.String(), "PANIC in tool panicking_tool") || !strings.Contains(logs.String(), "goroutine") {
		t.Errorf("panic must be logged with tool name + stack, got log: %.300s", logs.String())
	}
}
