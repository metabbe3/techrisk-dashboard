package tools

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

func TestWebToolsDisabled(t *testing.T) {
	reg := NewRegistry(&config.Config{WebToolsEnabled: false})
	if reg.Has("web_search") || reg.Has("web_fetch") {
		t.Fatal("web tools must not be registered when disabled")
	}
	_, err := reg.Execute("web_search", `{"query":"x"}`)
	if err == nil || !strings.Contains(err.Error(), "unknown tool") {
		t.Fatalf("expected unknown-tool error, got %v", err)
	}
}

func TestWebFetchSSRFBlocked(t *testing.T) {
	// A local httptest server is loopback — the guarded client must refuse it.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("server must never be reached")
	}))
	defer srv.Close()

	reg := NewRegistry(&config.Config{WebToolsEnabled: true})
	for _, target := range []string{srv.URL, "http://169.254.169.254/latest/meta-data", "http://10.0.0.1/x", "http://[::1]/"} {
		out, err := reg.Execute("web_fetch", `{"url":"`+target+`"}`)
		if err != nil {
			t.Fatalf("web_fetch %s: %v", target, err)
		}
		if !strings.Contains(out, "Error executing web_fetch") {
			t.Errorf("web_fetch %s should be blocked, got %q", target, out)
		}
	}
}

func TestWebFetchSchemeRejected(t *testing.T) {
	reg := NewRegistry(&config.Config{WebToolsEnabled: true})
	for _, target := range []string{"file:///etc/passwd", "ftp://example.com/x"} {
		out, _ := reg.Execute("web_fetch", `{"url":"`+target+`"}`)
		if !strings.Contains(out, "must be absolute http(s)") {
			t.Errorf("%s should be rejected as non-http(s), got %q", target, out)
		}
	}
}

func TestWebSearchParsesDDG(t *testing.T) {
	page := `<a class="result__a" href="//example.com/one">One &amp; Only</a>
	<a class="result__snippet" href="">First &lt;sentence&gt;</a>
	<a class="result__a" href="/l/?uddg=https%3A%2F%2Fexample.org%2Ftwo">Second <b>result</b></a>
	<a class="result__snippet" href="">About the second</a>`

	results := parseDDG(page)
	if len(results) != 2 {
		t.Fatalf("results = %d, want 2", len(results))
	}
	if results[0].link != "https://example.com/one" || results[0].title != "One & Only" {
		t.Errorf("result 0 = %+v", results[0])
	}
	if results[1].link != "https://example.org/two" || results[1].title != "Second result" {
		t.Errorf("result 1 = %+v", results[1])
	}
	if results[0].snippet != "First <sentence>" {
		t.Errorf("snippet 0 = %q", results[0].snippet)
	}
}

func TestWebSearchViaServer(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if got := r.URL.Query().Get("q"); got != "dana payment outage" {
			t.Errorf("query = %q", got)
		}
		w.Write([]byte(`<a class="result__a" href="https://status.example/x">Status page</a>
		<a class="result__snippet" href="">All systems go</a>`))
	}))
	defer srv.Close()

	prev := ddgEndpoint
	ddgEndpoint = srv.URL
	t.Cleanup(func() { ddgEndpoint = prev })

	reg := NewRegistry(&config.Config{WebToolsEnabled: true})
	if !reg.Has("web_search") {
		t.Fatal("web_search should be registered when enabled")
	}
	out, err := reg.Execute("web_search", `{"query":"dana payment outage"}`)
	if err != nil {
		t.Fatalf("web_search: %v", err)
	}
	if !strings.Contains(out, "Status page") || !strings.Contains(out, "https://status.example/x") {
		t.Errorf("results missing from output: %s", out)
	}
}

func TestWebFetchStripsAndTruncates(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("<html><script>evil()</script><body><h1>Title</h1><p>Body text</p>" +
			strings.Repeat("pad ", 4000) + "</body></html>"))
	}))
	defer srv.Close()

	wt := &webTools{fetchClient: srv.Client(), searchClient: srv.Client()} // plain client: test seam
	out, err := wt.handleWebFetch(`{"url":"` + srv.URL + `"}`)
	if err != nil {
		t.Fatalf("web_fetch: %v", err)
	}
	if strings.Contains(out, "evil()") {
		t.Error("script content leaked into output")
	}
	if !strings.Contains(out, "Title") || !strings.Contains(out, "Body text") {
		t.Errorf("text content missing: %.80s", out)
	}
	if !strings.Contains(out, "[truncated") {
		t.Error("oversize page should carry a truncation footer")
	}
	if len(out) > webResultMaxChars+200 {
		t.Errorf("output too long: %d", len(out))
	}
}
