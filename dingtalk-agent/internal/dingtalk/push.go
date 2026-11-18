package dingtalk

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"mime/multipart"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// maxDMRecipients mirrors the documented batchSend ceiling (20 per call).
const maxDMRecipients = 20

// robotSend is the ONE robot-message send: groups go via
// /v1.0/robot/groupMessages/send, DMs via /v1.0/robot/oToMessages/batchSend.
// Callers pass an already-marshalled msgParam and the client they already
// hold — a fresh client per send meant a fresh access-token round-trip.
func (c *openAPIClient) robotSend(ctx context.Context, convoType, convoID, staffID, msgKey, msgParam string) error {
	if convoType == "2" {
		return c.do(ctx, http.MethodPost, "/v1.0/robot/groupMessages/send", map[string]any{
			"robotCode":          c.appKey,
			"openConversationId": convoID,
			"msgKey":             msgKey,
			"msgParam":           msgParam,
		})
	}
	return c.do(ctx, http.MethodPost, "/v1.0/robot/oToMessages/batchSend", map[string]any{
		"robotCode": c.appKey,
		"userIds":   []string{staffID},
		"msgKey":    msgKey,
		"msgParam":  msgParam,
	})
}

// sendMarkdownParam marshals the {title,text} payload every sampleMarkdown
// send carries.
func sendMarkdownParam(title, markdown string) (string, error) {
	b, err := json.Marshal(map[string]string{"title": title, "text": markdown})
	return string(b), err
}

func sendFileParam(mediaID, fileName string) (string, error) {
	b, err := json.Marshal(map[string]any{"mediaId": mediaID, "fileName": fileName, "fileDuration": 0})
	return string(b), err
}

// SendMarkdownDM proactively pushes a robot DM (markdown) to staffIds via
// POST /v1.0/robot/oToMessages/batchSend. Requires the app permission
// qyapi_robot_sendmsg ("send messages with a bot in your organization") and
// recipients who have opened a chat with the robot at least once.
func SendMarkdownDM(ctx context.Context, cfg *config.Config, staffIDs []string, title, markdown string) error {
	if len(staffIDs) > maxDMRecipients {
		staffIDs = staffIDs[:maxDMRecipients]
	}
	msgParam, err := sendMarkdownParam(title, markdown)
	if err != nil {
		return err
	}
	client := newOpenAPIClient(cfg)
	return client.do(ctx, http.MethodPost, "/v1.0/robot/oToMessages/batchSend", map[string]any{
		"robotCode": cfg.DingTalkClientID,
		"userIds":   staffIDs,
		"msgKey":    "sampleMarkdown",
		"msgParam":  msgParam,
	})
}

// deliverMedia ships an uploaded file: group ask tries the group first (the
// file appears where the question was asked) and falls back to the sender's
// DM when the robot lacks the group-send capability; a DM ask delivers to the
// DM. Returns the user-visible note.
func (b *Bot) deliverMedia(ctx context.Context, m *chatbot.BotCallbackDataModel, mediaID, fileName string) string {
	param, err := sendFileParam(mediaID, fileName)
	if err != nil {
		return "📎 " + fileName + " — delivery failed: " + err.Error()
	}
	if m.ConversationType != "2" { // p2p chat (or unknown): DM the sender
		if err := b.api.robotSend(ctx, "1", "", m.SenderStaffId, "sampleFile", param); err != nil {
			log.Printf("[dingtalk] file DM failed for %s: %v", fileName, err)
			return "📎 " + fileName + " — delivery failed, see server log"
		}
		return "📎 **" + fileName + "** — sent to your DM ✓"
	}
	if err := b.api.robotSend(ctx, "2", m.ConversationId, "", "sampleFile", param); err != nil {
		log.Printf("[dingtalk] group file send failed for %s: %v — falling back to DM", fileName, err)
		if err := b.api.robotSend(ctx, "1", "", m.SenderStaffId, "sampleFile", param); err != nil {
			log.Printf("[dingtalk] file DM failed for %s: %v", fileName, err)
			return "📎 " + fileName + " — delivery failed, see server log"
		}
		return "📎 **" + fileName + "** — group send failed, sent to your DM ✓"
	}
	return "📎 **" + fileName + "** — sent to the group ✓"
}

// deliverMarkdown routes a proactive markdown push by conversation type:
// groups go via the group endpoint, and when that endpoint rejects the robot
// (missing group-send capability — live-probed "robot 不存在") the message
// falls back to the creator's DM rather than being lost. The group error is
// JOINED — it was swallowed before, so a group+DM double failure looked like
// success. An empty staffID skips the pointless DM attempt.
func (b *Bot) deliverMarkdown(ctx context.Context, convoID, convoType, staffID, title, text string) error {
	param, err := sendMarkdownParam(title, text)
	if err != nil {
		return err
	}
	if convoType != "2" {
		if staffID == "" {
			return fmt.Errorf("no staff id to DM %q", title)
		}
		return b.api.robotSend(ctx, "1", "", staffID, "sampleMarkdown", param)
	}
	err = b.api.robotSend(ctx, "2", convoID, "", "sampleMarkdown", param)
	if err == nil {
		return nil
	}
	log.Printf("[dingtalk] group send failed (%v) — falling back to DM", err)
	if staffID == "" {
		return err
	}
	fb, ferr := sendMarkdownParam(title, text+"\n\n_(group delivery failed — sent here instead)_")
	if ferr != nil {
		return errors.Join(err, ferr)
	}
	if derr := b.api.robotSend(ctx, "1", "", staffID, "sampleMarkdown", fb); derr != nil {
		return errors.Join(err, derr)
	}
	return nil
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
		return "", fmt.Errorf("media upload failed: %w", errSansURL(err))
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

// errSansURL strips *url.Error's URL context: the upload URL carries the
// access_token as a query param and url.Error prints it verbatim.
func errSansURL(err error) error {
	var ue *url.Error
	if errors.As(err, &ue) {
		return ue.Err
	}
	return err
}
