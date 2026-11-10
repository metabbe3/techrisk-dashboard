package dingtalk

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"

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

// uploadRobotMedia uploads a file for robot DM delivery via multipart POST
// /v1.0/robot/messageFiles/upload (fields: robotCode, fileType=file, file).
// Returns the mediaId. ponytail: contract per DingTalk docs — pinned live
// 2026-11-10; see README deliverables section before changing.
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
	_ = w.WriteField("robotCode", c.appKey)
	_ = w.WriteField("fileType", "file")
	fw, err := w.CreateFormFile("file", filepath.Base(path))
	if err != nil {
		return "", err
	}
	if _, err := io.Copy(fw, f); err != nil {
		return "", err
	}
	if err := w.Close(); err != nil {
		return "", err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.host+"/v1.0/robot/messageFiles/upload", &body)
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", w.FormDataContentType())
	req.Header.Set("x-acs-dingtalk-access-token", token)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("media upload failed: %w", err)
	}
	defer resp.Body.Close()

	var out struct {
		MediaID string `json:"mediaId"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return "", fmt.Errorf("media upload decode failed (HTTP %d): %w", resp.StatusCode, err)
	}
	if out.MediaID == "" {
		return "", fmt.Errorf("no mediaId in upload response (HTTP %d) — endpoint contract may differ, see README", resp.StatusCode)
	}
	return out.MediaID, nil
}
