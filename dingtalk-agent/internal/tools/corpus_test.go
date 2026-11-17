package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// TestReadCorpusPageRejectsUnsafePaths pins the client-side guard: every bad
// path must be rejected WITHOUT reaching the server (the server re-validates —
// this test's handler fails the test if it is ever called).
func TestReadCorpusPageRejectsUnsafePaths(t *testing.T) {
	cases := []string{
		"",
		"   ",
		"../secret.md",
		"topics/../../.env",
		"/etc/passwd.md",
		`topics\payments.md`,
		"topics/payments.pdf",
		"topics/notes.md%00.png",
	}
	for _, bad := range cases {
		called := false
		reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
			called = true
			envelope(w, map[string]any{"content": "should never be read"}, nil)
		})
		args, _ := json.Marshal(map[string]string{"path": bad})
		out, err := reg.Execute(context.Background(), "read_corpus_page", string(args))
		if err != nil {
			t.Fatalf("path %q: Execute itself failed: %v", bad, err)
		}
		// Handler rejections surface as error CONTENT (registry.go Execute), not Go errors.
		if !strings.Contains(out, "Error executing read_corpus_page") {
			t.Errorf("path %q: expected rejection content, got %.80s", bad, out)
		}
		if called {
			t.Errorf("path %q: request reached the server", bad)
		}
	}
}

func TestReadCorpusPageFetchesFromAPI(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/corpus" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		if got := r.URL.Query().Get("path"); got != "topics/payments.md" {
			t.Errorf("unexpected query path: %q", got)
		}
		envelope(w, map[string]any{
			"path":      "topics/payments.md",
			"content":   "# Patterns: Payments\n\ngateway timeouts (2026_IN_0001)\n",
			"truncated": false,
			"chars":     44,
		}, nil)
	})

	args, _ := json.Marshal(map[string]string{"path": "topics/payments.md"})
	out, err := reg.Execute(context.Background(), "read_corpus_page", string(args))
	if err != nil {
		t.Fatalf("read_corpus_page failed: %v", err)
	}
	if !strings.Contains(out, "# Patterns: Payments") || !strings.Contains(out, "2026_IN_0001") {
		t.Errorf("content not served verbatim: %.120s", out)
	}
	if strings.Contains(out, "truncated") {
		t.Errorf("clean read must not mention truncation: %.120s", out)
	}
}

func TestReadCorpusPageNotesServerTruncation(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, map[string]any{
			"path":      "topics/index.md",
			"content":   "# Index\n...",
			"truncated": true,
			"chars":     100000,
		}, nil)
	})

	args, _ := json.Marshal(map[string]string{"path": "topics/index.md"})
	out, err := reg.Execute(context.Background(), "read_corpus_page", string(args))
	if err != nil {
		t.Fatalf("read_corpus_page failed: %v", err)
	}
	if !strings.Contains(out, "[truncated") {
		t.Errorf("truncated page must carry a note: %.160s", out)
	}
}

func TestReadCorpusPage404SurfacesHint(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusNotFound)
		fmt.Fprint(w, `{"code":404,"status":"Error","message":"Corpus page not found — read index.md for what exists."}`)
	})

	args, _ := json.Marshal(map[string]string{"path": "ghost.md"})
	out, err := reg.Execute(context.Background(), "read_corpus_page", string(args))
	if err != nil {
		t.Fatalf("404 must surface as error content, not a hard failure: %v", err)
	}
	if !strings.Contains(out, "read index.md for what exists") {
		t.Errorf("404 must carry the server hint: %.160s", out)
	}
}

func TestReadCorpusPageIsRegistered(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, map[string]any{"content": "x", "truncated": false, "chars": 1}, nil)
	})
	if !reg.Has("read_corpus_page") {
		t.Error("read_corpus_page not registered")
	}
}
