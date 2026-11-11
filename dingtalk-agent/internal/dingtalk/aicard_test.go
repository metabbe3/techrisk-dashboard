package dingtalk

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// fakeDingTalk records every request path + body and mints access tokens.
type fakeDingTalk struct {
	mu     sync.Mutex
	calls  []string
	bodies []map[string]any

	tokens int
	srv    *httptest.Server
}

func newFakeDingTalk(t *testing.T) *fakeDingTalk {
	t.Helper()
	f := &fakeDingTalk{}
	f.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		var parsed map[string]any
		_ = json.Unmarshal(body, &parsed)

		f.mu.Lock()
		f.calls = append(f.calls, r.Method+" "+r.URL.Path)
		f.bodies = append(f.bodies, parsed)
		isTokenReq := r.URL.Path == "/v1.0/oauth2/accessToken"
		f.mu.Unlock()

		w.Header().Set("Content-Type", "application/json")
		if isTokenReq {
			f.mu.Lock()
			f.tokens++
			f.mu.Unlock()
			fmt.Fprint(w, `{"accessToken":"tok-1","expireIn":7200}`)
			return
		}
		fmt.Fprint(w, `{}`)
	}))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *fakeDingTalk) client() *openAPIClient {
	cfg := &config.Config{DingTalkClientID: "app", DingTalkClientSecret: "sec", DingTalkOpenAPIHost: f.srv.URL}
	return newOpenAPIClient(cfg)
}

func (f *fakeDingTalk) call(i int) string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.calls[i]
}

func (f *fakeDingTalk) body(i int) map[string]any {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.bodies[i]
}

func TestOpenAPIClientTokenCached(t *testing.T) {
	f := newFakeDingTalk(t)
	c := f.client()
	for i := 0; i < 3; i++ {
		if _, err := c.accessToken(context.Background()); err != nil {
			t.Fatalf("accessToken: %v", err)
		}
	}
	if f.tokens != 1 {
		t.Errorf("token requests = %d, want 1 (cached)", f.tokens)
	}
}

func TestNewAICardGroup(t *testing.T) {
	f := newFakeDingTalk(t)
	m := &chatbot.BotCallbackDataModel{
		MsgId: "mid", SenderStaffId: "staff1", SenderCorpId: "corp1",
		ConversationId: "cid1", ConversationType: "2",
	}
	card, err := newAICard(context.Background(), f.client(), m, "Risk Agent")
	if err != nil {
		t.Fatalf("newAICard: %v", err)
	}
	if len(f.calls) != 3 { // token + create + deliver
		t.Fatalf("calls = %v", f.calls)
	}
	create := f.body(1)
	if create["cardTemplateId"] != aiCardTemplate || create["callbackType"] != "STREAM" {
		t.Errorf("create body = %v", create)
	}
	deliver := f.body(2)
	if deliver["openSpaceId"] != "dtv1.card//IM_GROUP.cid1" {
		t.Errorf("group deliver openSpaceId = %v", deliver["openSpaceId"])
	}

	if err := card.stream(context.Background(), "par"); err != nil {
		t.Fatalf("stream: %v", err)
	}
	streamBody := f.body(3)
	if streamBody["key"] != "msgContent" || streamBody["content"] != "par" || streamBody["isFull"] != true {
		t.Errorf("stream body = %v", streamBody)
	}
	if streamBody["outTrackId"] != card.outTrackID {
		t.Errorf("stream outTrackId %v != card %v", streamBody["outTrackId"], card.outTrackID)
	}

	if err := card.finish(context.Background(), "done"); err != nil {
		t.Fatalf("finish: %v", err)
	}
	finishParams := f.body(4)["cardData"].(map[string]any)["cardParamMap"].(map[string]any)
	if finishParams["flowStatus"] != "3" || finishParams["msgContent"] != "done" {
		t.Errorf("finish params = %v", finishParams)
	}

	if err := card.failWith(context.Background(), "failed, try again"); err != nil {
		t.Fatalf("failWith: %v", err)
	}
	failParams := f.body(5)["cardData"].(map[string]any)["cardParamMap"].(map[string]any)
	if failParams["flowStatus"] != "5" || failParams["msgContent"] != "failed, try again" {
		t.Errorf("fail params = %v", failParams)
	}
}

func TestNewAICardDM(t *testing.T) {
	f := newFakeDingTalk(t)
	m := &chatbot.BotCallbackDataModel{MsgId: "m", SenderStaffId: "s", ConversationType: "1"}
	if _, err := newAICard(context.Background(), f.client(), m, "Risk Agent"); err != nil {
		t.Fatalf("newAICard: %v", err)
	}
	if !strings.Contains(fmt.Sprint(f.body(2)["openSpaceId"]), "IM_ROBOT.s") {
		t.Errorf("DM deliver openSpaceId = %v", f.body(2)["openSpaceId"])
	}
}

func TestOpenAPIClientAPIError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/v1.0/oauth2/accessToken" {
			fmt.Fprint(w, `{"accessToken":"tok","expireIn":7200}`)
			return
		}
		http.Error(w, `{"code":"Forbidden"}`, http.StatusForbidden)
	}))
	defer srv.Close()

	cfg := &config.Config{DingTalkClientID: "a", DingTalkClientSecret: "b", DingTalkOpenAPIHost: srv.URL}
	err := newOpenAPIClient(cfg).do(context.Background(), http.MethodPost, "/v1.0/card/instances", map[string]any{})
	if err == nil || !strings.Contains(err.Error(), "403") {
		t.Fatalf("want 403 error, got %v", err)
	}
}
