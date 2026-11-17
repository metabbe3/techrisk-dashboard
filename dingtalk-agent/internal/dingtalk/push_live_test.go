package dingtalk

// Live-pinned attachment delivery tests (guarded — skipped unless env set):
//
//	LIVE_DM_STAFF=<staffId>   go test ./internal/dingtalk -run TestLiveFileDMAttachment -v
//	LIVE_DM_CID=<cid...>      (with the above) also probes GROUP file delivery
//
// They exercise the exact upload→sampleFile path deliverGenerated uses, so a
// scope/permission gap on the DingTalk app surfaces here with the raw API error.

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// mustFileParam marshals the sampleFile payload or fails the probe.
func mustFileParam(t *testing.T, mediaID, fileName string) string {
	t.Helper()
	b, err := sendFileParam(mediaID, fileName)
	if err != nil {
		t.Fatalf("msgParam: %v", err)
	}
	return b
}

func liveConfig(t *testing.T) *config.Config {
	t.Helper()
	for _, p := range []string{"../../dingtalk-agent.env", "../dingtalk-agent.env", "dingtalk-agent.env"} {
		if _, err := os.Stat(p); err == nil {
			cfg, err := config.LoadFromFile(p)
			if err != nil {
				t.Fatalf("load env: %v", err)
			}
			return cfg
		}
	}
	t.Skip("dingtalk-agent.env not found")
	return nil
}

func TestLiveFileDMAttachment(t *testing.T) {
	staff := os.Getenv("LIVE_DM_STAFF")
	if staff == "" {
		t.Skip("set LIVE_DM_STAFF=<staffId> to run the live attachment probe")
	}
	cfg := liveConfig(t)
	api := newOpenAPIClient(cfg)

	path := filepath.Join(t.TempDir(), "live-attach-probe.txt")
	if err := os.WriteFile(path, []byte("live attachment probe — safe to delete.\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	mediaID, err := api.uploadRobotMedia(ctx, path)
	if err != nil {
		t.Fatalf("uploadRobotMedia: %v", err)
	}
	t.Logf("mediaId=%s", mediaID)

	if err := newOpenAPIClient(cfg).robotSend(ctx, "1", "", staff, "sampleFile", mustFileParam(t, mediaID, filepath.Base(path))); err != nil {
		t.Fatalf("file DM send: %v", err)
	}
	t.Logf("file DM sent to %s — check the DingTalk DM for a downloadable attachment", staff)

	if cid := os.Getenv("LIVE_DM_CID"); cid != "" {
		if err := newOpenAPIClient(cfg).robotSend(ctx, "2", cid, "", "sampleFile", mustFileParam(t, mediaID, filepath.Base(path))); err != nil {
			t.Logf("group file send probe failed (scope or contract): %v", err)
		} else {
			t.Logf("group file send sent to %s — check the group for the attachment", cid)
		}
		if err := newOpenAPIClient(cfg).robotSend(ctx, "2", cid, "", "sampleMarkdown", `{"title":"probe","text":"**live probe** — group send capability test, safe to ignore."}`); err != nil {
			t.Logf("group markdown send probe failed: %v", err)
			return
		}
		t.Logf("group markdown send WORKS for %s", cid)
	}
}
