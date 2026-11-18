package dingtalk

import (
	"context"
	"sync"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

func TestSendMarkdownDM(t *testing.T) {
	f := newFakeDingTalk(t)
	cfg := &config.Config{DingTalkClientID: "app", DingTalkOpenAPIHost: f.srv.URL}

	ids := make([]string, 25)
	for i := range ids {
		ids[i] = string(rune('a' + i))
	}
	if err := SendMarkdownDM(context.Background(), cfg, ids, "T", "# hello"); err != nil {
		t.Fatalf("SendMarkdownDM: %v", err)
	}

	body := f.body(1) // 0 = token fetch
	if body["robotCode"] != "app" || body["msgKey"] != "sampleMarkdown" {
		t.Errorf("body = %v", body)
	}
	if body["msgParam"] != `{"text":"# hello","title":"T"}` {
		t.Errorf("msgParam = %v", body["msgParam"])
	}
	userIDs, _ := body["userIds"].([]any)
	if len(userIDs) != maxDMRecipients {
		t.Errorf("userIds = %d, want capped at %d", len(userIDs), maxDMRecipients)
	}
}

// The upload URL carries the access token as a query param; a transport-level
// failure wraps in *url.Error, whose Error() prints the whole URL — the token
// used to ride into the central log.
func TestUploadErrorHidesToken(t *testing.T) {
	var mu sync.Mutex
	var tokenSeen string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.Contains(r.URL.Path, "accessToken") {
			_, _ = w.Write([]byte(`{"accessToken":"sekret-token","expireIn":7200}`))
			return
		}
		mu.Lock()
		tokenSeen = r.URL.RawQuery
		mu.Unlock()
		// RST the connection mid-upload: the client surfaces a *url.Error.
		conn, _, _ := w.(http.Hijacker).Hijack()
		_ = conn.Close()
	}))
	defer srv.Close()

	cfg := &config.Config{DingTalkClientID: "app", DingTalkOpenAPIHost: srv.URL}
	api := newOpenAPIClient(cfg)
	path := filepath.Join(t.TempDir(), "x.pdf")
	_ = os.WriteFile(path, []byte("%PDF"), 0o644)

	_, err := api.uploadRobotMedia(context.Background(), path)
	if err == nil {
		t.Fatal("upload must fail")
	}
	if strings.Contains(err.Error(), "sekret-token") || strings.Contains(err.Error(), "access_token") {
		t.Errorf("error leaks the token: %v", err)
	}
	mu.Lock()
	reached := tokenSeen != ""
	mu.Unlock()
	if !reached {
		t.Error("upload must have reached the server")
	}
}
