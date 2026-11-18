package tools

import (
	"context"
	"net/http"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// TestExecuteRecoversFromPanickingTool pins the panic guard: a panicking
// handler must surface as error CONTENT (like any handler error), never kill
// the process. Before the guard a panicking tool took the whole bot down
// (findings.md "Wave-1 crash hardening" — chat, scheduled and mesh paths all
// dispatch through this one seam).
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

	out, err := reg.Execute(context.Background(), "panicking_tool", "{}")
	if err != nil {
		t.Fatalf("recovered panic must surface as content, not a Go error: %v", err)
	}
	if !strings.Contains(out, "internal panic") || !strings.Contains(out, "boom") {
		t.Errorf("expected panic content in result, got %.120s", out)
	}
}
