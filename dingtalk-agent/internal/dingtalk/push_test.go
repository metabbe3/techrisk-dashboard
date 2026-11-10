package dingtalk

import (
	"context"
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
