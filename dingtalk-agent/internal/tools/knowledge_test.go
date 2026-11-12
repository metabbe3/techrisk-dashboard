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

// save_knowledge files synchronously (searchable as Execute returns);
// list_knowledge shows the doc; delete_knowledge needs the exact title and
// reports what it removed.
func TestSaveListDeleteKnowledgeTools(t *testing.T) {
	ix := kb.NewIndex(t.TempDir(), "m", sameEmb{})
	reg := NewRegistry(&config.Config{})
	reg.RegisterKnowledgeTools(ix)
	for _, name := range []string{"search_knowledge", "save_knowledge", "list_knowledge", "delete_knowledge"} {
		if !reg.Has(name) {
			t.Fatalf("%s not registered", name)
		}
	}

	out, err := reg.Execute(context.Background(), "save_knowledge", `{"title":"Deploy runbook","content":"restart the payment gateway"}`)
	if err != nil || !strings.Contains(out, "Filed") || !strings.Contains(out, "Deploy runbook") {
		t.Fatalf("save: %q, %v", out, err)
	}
	if hits, err := ix.Search(context.Background(), "payment gateway", 6); err != nil || len(hits) == 0 {
		t.Fatalf("save not searchable immediately: %+v, %v", hits, err)
	}

	out, err = reg.Execute(context.Background(), "list_knowledge", `{}`)
	if err != nil || !strings.Contains(out, "Deploy runbook") || !strings.Contains(out, "part") {
		t.Fatalf("list: %q, %v", out, err)
	}

	if out, _ := reg.Execute(context.Background(), "delete_knowledge", `{"title":"Nope"}`); !strings.Contains(out, "Error executing") {
		t.Fatalf("missing-title delete not an error: %s", out)
	}
	if out, _ := reg.Execute(context.Background(), "delete_knowledge", `{"title":"Deploy runbook"}`); !strings.Contains(out, "Deleted") || !strings.Contains(out, "Deploy runbook") {
		t.Fatalf("delete: %s", out)
	}
	if docs := ix.ListDocs(); len(docs) != 0 {
		t.Fatalf("delete left docs: %+v", docs)
	}
	if out, _ := reg.Execute(context.Background(), "list_knowledge", `{}`); !strings.Contains(out, "empty") {
		t.Fatalf("empty list message: %s", out)
	}
}

// Validation limits mirror search_knowledge's; a registry that never got the
// index errors instead of panicking.
func TestKBSaveValidation(t *testing.T) {
	ix := kb.NewIndex(t.TempDir(), "m", sameEmb{})
	reg := NewRegistry(&config.Config{})
	reg.RegisterKnowledgeTools(ix)

	cases := []struct{ name, tool, args string }{
		{"blank title", "save_knowledge", `{"title":"  ","content":"x"}`},
		{"blank content", "save_knowledge", `{"title":"t","content":"  "}`},
		{"oversize title", "save_knowledge", `{"title":"` + strings.Repeat("t", 201) + `","content":"x"}`},
		{"oversize content", "save_knowledge", `{"title":"t","content":"` + strings.Repeat("c", 100001) + `"}`},
		{"oversize delete title", "delete_knowledge", `{"title":"` + strings.Repeat("t", 201) + `"}`},
	}
	for _, c := range cases {
		if out, _ := reg.Execute(context.Background(), c.tool, c.args); !strings.Contains(out, "Error executing") {
			t.Errorf("%s not rejected: %s", c.name, out)
		}
	}

	bare := NewRegistry(&config.Config{})
	if _, err := bare.handleListKnowledge(context.Background(), struct{}{}); err == nil {
		t.Error("nil index must error")
	}
	if _, err := bare.handleSaveKnowledge(context.Background(), struct {
		Title   string `json:"title"`
		Content string `json:"content"`
	}{Title: "t", Content: "c"}); err == nil {
		t.Error("nil index must error")
	}
	if _, err := bare.handleDeleteKnowledge(context.Background(), struct {
		Title string `json:"title"`
	}{Title: "t"}); err == nil {
		t.Error("nil index must error")
	}
}
