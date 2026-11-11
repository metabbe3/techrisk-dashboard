package dingtalk

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// maxDMRecipients mirrors the documented batchSend ceiling (20 per call).
const maxDMRecipients = 20

// SendMarkdownDM proactively pushes a robot DM (markdown) to staffIds via
// POST /v1.0/robot/oToMessages/batchSend. Requires the app permission
// qyapi_robot_sendmsg ("send messages with a bot in your organization") and
// recipients who have opened a chat with the robot at least once.
func SendMarkdownDM(ctx context.Context, cfg *config.Config, staffIDs []string, title, markdown string) error {
	if len(staffIDs) > maxDMRecipients {
		staffIDs = staffIDs[:maxDMRecipients]
	}
	msgParam, err := json.Marshal(map[string]string{"title": title, "text": markdown})
	if err != nil {
		return err
	}
	client := newOpenAPIClient(cfg)
	return client.do(ctx, http.MethodPost, "/v1.0/robot/oToMessages/batchSend", map[string]any{
		"robotCode": cfg.DingTalkClientID,
		"userIds":   staffIDs,
		"msgKey":    "sampleMarkdown",
		"msgParam":  string(msgParam),
	})
}

// SendFileDM pushes a robot file DM (msgKey sampleFile) via the same
// batchSend path. mediaID comes from uploadRobotMedia.
func SendFileDM(ctx context.Context, cfg *config.Config, staffID, mediaID, fileName string) error {
	msgParam, err := json.Marshal(map[string]any{"mediaId": mediaID, "fileName": fileName, "fileDuration": 0})
	if err != nil {
		return err
	}
	client := newOpenAPIClient(cfg)
	return client.do(ctx, http.MethodPost, "/v1.0/robot/oToMessages/batchSend", map[string]any{
		"robotCode": cfg.DingTalkClientID,
		"userIds":   []string{staffID},
		"msgKey":    "sampleFile",
		"msgParam":  string(msgParam),
	})
}

// SendFileGroup pushes a robot file message INTO a group conversation
// (msgKey sampleFile) via POST /v1.0/robot/groupMessages/send. The bot must
// be in the group; conversationID is the openConversationId (bot callbacks
// carry it as ConversationId).
func SendFileGroup(ctx context.Context, cfg *config.Config, conversationID, mediaID, fileName string) error {
	msgParam, err := json.Marshal(map[string]any{"mediaId": mediaID, "fileName": fileName, "fileDuration": 0})
	if err != nil {
		return err
	}
	client := newOpenAPIClient(cfg)
	return client.do(ctx, http.MethodPost, "/v1.0/robot/groupMessages/send", map[string]any{
		"robotCode":          cfg.DingTalkClientID,
		"openConversationId": conversationID,
		"msgKey":             "sampleFile",
		"msgParam":           string(msgParam),
	})
}

// uploadRobotMedia uploads a file for robot message delivery via the LEGACY
// media upload API — POST https://oapi.dingtalk.com/media/upload
// (query: access_token, type=file; multipart field: "media" → media_id).
// ponytail: /v1.0/robot/messageFiles/upload was the documented contract but
// live-probes 404 (2026-11-11, unauthenticated too — path is dead); oapi
// media/upload is the endpoint that actually exists and feeds batchSend.
func (c *openAPIClient) uploadRobotMedia(ctx context.Context, path string) (string, error) {
	token, err := c.accessToken(ctx)
	if err != nil {
		return "", err
	}

	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()

	var body bytes.Buffer
	w := multipart.NewWriter(&body)
	fw, err := w.CreateFormFile("media", filepath.Base(path))
	if err != nil {
		return "", err
	}
	if _, err := io.Copy(fw, f); err != nil {
		return "", err
	}
	if err := w.Close(); err != nil {
		return "", err
	}

	// Legacy APIs live on oapi.* and take the (new-style) token as a query param.
	uploadURL := strings.Replace(c.host, "//api.", "//oapi.", 1) +
		"/media/upload?" + url.Values{"access_token": {token}, "type": {"file"}}.Encode()

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, uploadURL, &body)
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", w.FormDataContentType())

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("media upload failed: %w", err)
	}
	defer resp.Body.Close()

	var out struct {
		Errcode int    `json:"errcode"`
		Errmsg  string `json:"errmsg"`
		MediaID string `json:"media_id"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return "", fmt.Errorf("media upload decode failed (HTTP %d): %w", resp.StatusCode, err)
	}
	if out.Errcode != 0 || out.MediaID == "" {
		return "", fmt.Errorf("media upload rejected (HTTP %d): errcode=%d %s", resp.StatusCode, out.Errcode, out.Errmsg)
	}
	return out.MediaID, nil
}
