package tools

import (
	"context"
	"strings"
	"testing"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/kb"
)

// sameEmb returns the same vector for every input — enough to exercise the
// search_knowledge handler's formatting without a real embedding model.
type sameEmb struct{}

func (sameEmb) Embed(_ context.Context, inputs []string) ([][]float32, error) {
	out := make([][]float32, len(inputs))
	for i := range out {
		out[i] = []float32{1, 0}
	}
	return out, nil
}

func TestSearchKnowledgeTool(t *testing.T) {
	ix := kb.NewIndex(t.TempDir(), "m", sameEmb{})
	reg := NewRegistry(&config.Config{})
	reg.RegisterKnowledgeTools(ix)
	if !reg.Has("search_knowledge") || reg.KBIndex() != ix {
		t.Fatal("search_knowledge not registered on the index")
	}

	// Ingest is async — wait until the doc is searchable.
	ix.Ingest("SOP.pdf", "restart the payment gateway")
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if hits, _ := ix.Search(context.Background(), "restart", 6); len(hits) > 0 {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}

	out, err := reg.Execute(context.Background(), "search_knowledge", `{"query":"restart gateway"}`)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "SOP.pdf") || !strings.Contains(out, "restart the payment gateway") {
		t.Fatalf("result missing doc/passage: %s", out)
	}

	// Blank or oversized queries are rejected (Execute wraps the error).
	if out, _ := reg.Execute(context.Background(), "search_knowledge", `{"query":"  "}`); !strings.Contains(out, "Error executing") {
		t.Fatalf("blank query not rejected: %s", out)
	}
	if out, _ := reg.Execute(context.Background(), "search_knowledge", `{"query":"`+strings.Repeat("q", 501)+`"}`); !strings.Contains(out, "Error executing") {
		t.Fatalf("oversized query not rejected: %s", out)
	}
}
