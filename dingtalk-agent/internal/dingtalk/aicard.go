package dingtalk

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"sync"
	"time"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// DefaultOpenAPIHost is DingTalk's China OpenAPI endpoint (the SDK default).
const DefaultOpenAPIHost = "https://api.dingtalk.com"

// aiCardTemplate is DingTalk's standard AI markdown card ("打字机" typewriter card):
// flowStatus 1 processing → 2 inputing → 3 finished / 5 failed.
const aiCardTemplate = "382e4302-551d-4880-bf29-a30acfab2e71.schema"

const (
	flowStatusProcessing = 1
	flowStatusFinished   = 3
	flowStatusFailed     = 5
)

// openAPIClient talks to DingTalk's REST OpenAPI with an app access token.
type openAPIClient struct {
	appKey     string
	appSecret  string
	host       string
	httpClient *http.Client

	mu         sync.Mutex
	token      string
	tokenValid time.Time
}

func newOpenAPIClient(cfg *config.Config) *openAPIClient {
	host := cfg.DingTalkOpenAPIHost
	if host == "" {
		host = DefaultOpenAPIHost
	}
	return &openAPIClient{
		appKey:     cfg.DingTalkClientID,
		appSecret:  cfg.DingTalkClientSecret,
		host:       host,
		httpClient: &http.Client{Timeout: 15 * time.Second},
	}
}

func (c *openAPIClient) accessToken(ctx context.Context) (string, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.token != "" && time.Now().Before(c.tokenValid) {
		return c.token, nil
	}

	body, _ := json.Marshal(map[string]string{
		"appKey":    c.appKey,
		"appSecret": c.appSecret,
	})
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.host+"/v1.0/oauth2/accessToken", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("token request failed: %w", err)
	}
	defer resp.Body.Close()

	var tokenResp struct {
		AccessToken string `json:"accessToken"`
		ExpireIn    int64  `json:"expireIn"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&tokenResp); err != nil {
		return "", fmt.Errorf("token decode failed (HTTP %d): %w", resp.StatusCode, err)
	}
	if tokenResp.AccessToken == "" {
		return "", fmt.Errorf("no access token in response (HTTP %d) — check app credentials", resp.StatusCode)
	}

	c.token = tokenResp.AccessToken
	c.tokenValid = time.Now().Add(time.Duration(tokenResp.ExpireIn-60) * time.Second) // 60s clock slack
	return c.token, nil
}

func (c *openAPIClient) do(ctx context.Context, method, path string, body any) error {
	return c.doResult(ctx, method, path, body, nil)
}

// doResult is do with an optional JSON-decoded success payload.
func (c *openAPIClient) doResult(ctx context.Context, method, path string, body any, out any) error {
	token, err := c.accessToken(ctx)
	if err != nil {
		return err
	}

	data, err := json.Marshal(body)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, method, c.host+path, bytes.NewReader(data))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("x-acs-dingtalk-access-token", token)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("%s %s failed: %w", method, path, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<10))
		return fmt.Errorf("%s %s -> HTTP %d: %s", method, path, resp.StatusCode, string(body))
	}
	if out != nil {
		if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(out); err != nil {
			return fmt.Errorf("%s %s decode: %w", method, path, err)
		}
	}
	return nil
}

// aiCard is one streaming AI card instance bound to a conversation.
type aiCard struct {
	client     *openAPIClient
	outTrackID string
}

// newAICard creates and delivers an AI card to the message's conversation.
// Mirrors the official SDK flow: POST /v1.0/card/instances, then /deliver.
func newAICard(ctx context.Context, client *openAPIClient, m *chatbot.BotCallbackDataModel, title string) (*aiCard, error) {
	// outTrackId: stable unique id derived from the message (same recipe as the official SDKs).
	factor := fmt.Sprintf("%s_%s_%s_%s", m.SenderStaffId, m.SenderCorpId, m.ConversationId, m.MsgId)
	sum := sha256.Sum256([]byte(factor + time.Now().Format(time.RFC3339Nano)))
	outTrackID := hex.EncodeToString(sum[:])

	paramMap := map[string]string{
		"msgContent": "",
		"msgTitle":   title,
		"sys_full_json_obj": marshalJSON(map[string]any{
			"order": []string{"msgTitle", "msgContent"},
		}),
		"flowStatus": fmt.Sprintf("%d", flowStatusProcessing),
	}

	createBody := map[string]any{
		"cardTemplateId":        aiCardTemplate,
		"outTrackId":            outTrackID,
		"cardData":              map[string]any{"cardParamMap": paramMap},
		"callbackType":          "STREAM",
		"imGroupOpenSpaceModel": map[string]any{"supportForward": true},
		"imRobotOpenSpaceModel": map[string]any{"supportForward": true},
	}
	if err := client.do(ctx, http.MethodPost, "/v1.0/card/instances", createBody); err != nil {
		return nil, fmt.Errorf("create card: %w", err)
	}

	// Delivery target: "2" = group conversation, "1" (default) = robot DM with the sender.
	deliverBody := map[string]any{"outTrackId": outTrackID, "userIdType": 1}
	if m.ConversationType == "2" {
		deliverBody["openSpaceId"] = "dtv1.card//IM_GROUP." + m.ConversationId
		deliverBody["imGroupOpenDeliverModel"] = map[string]any{"robotCode": client.appKey}
	} else {
		deliverBody["openSpaceId"] = "dtv1.card//IM_ROBOT." + m.SenderStaffId
		deliverBody["imRobotOpenDeliverModel"] = map[string]any{"spaceType": "IM_ROBOT"}
	}
	if err := client.do(ctx, http.MethodPost, "/v1.0/card/instances/deliver", deliverBody); err != nil {
		return nil, fmt.Errorf("deliver card: %w", err)
	}

	return &aiCard{client: client, outTrackID: outTrackID}, nil
}

// stream pushes the full accumulated content so far (typewriter update).
func (a *aiCard) stream(ctx context.Context, content string) error {
	return a.client.do(ctx, http.MethodPut, "/v1.0/card/streaming", map[string]any{
		"outTrackId": a.outTrackID,
		"guid":       newGUID(),
		"key":        "msgContent",
		"content":    content,
		"isFull":     true,
		"isFinalize": false,
		"isError":    false,
	})
}

// finish writes the final card state (flowStatus=3) with the complete content.
func (a *aiCard) finish(ctx context.Context, content string) error {
	return a.putCardData(ctx, content, flowStatusFinished)
}

// failWith marks the card failed (flowStatus=5) with a visible reason — an
// empty failed card reads as "finished" to the user (#27: fail+finish pair).
func (a *aiCard) failWith(ctx context.Context, text string) error {
	return a.putCardData(ctx, text, flowStatusFailed)
}

func (a *aiCard) putCardData(ctx context.Context, content string, flowStatus int) error {
	return a.client.do(ctx, http.MethodPut, "/v1.0/card/instances", map[string]any{
		"outTrackId": a.outTrackID,
		"cardData": map[string]any{"cardParamMap": map[string]string{
			"msgContent": content,
			"sys_full_json_obj": marshalJSON(map[string]any{
				"order": []string{"msgTitle", "msgContent"},
			}),
			"flowStatus": fmt.Sprintf("%d", flowStatus),
		}},
	})
}

func marshalJSON(v any) string {
	data, err := json.Marshal(v)
	if err != nil {
		return "{}"
	}
	return string(data)
}

// newGUID is a random RFC-4122-ish v4 uuid (only used as a streaming request id).
func newGUID() string {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return time.Now().Format("20060102150405.000000000")
	}
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}
