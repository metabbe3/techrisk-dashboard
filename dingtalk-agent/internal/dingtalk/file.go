package dingtalk

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"time"
)

// MaxFileDownloadBytes caps message-file downloads.
const MaxFileDownloadBytes = 15 << 20 // 15 MB

// fileMessage is the Content payload of a msgtype="file" chatbot callback.
type fileMessage struct {
	DownloadCode string `json:"downloadCode"`
	FileName     string `json:"fileName"`
	FileSize     int64  `json:"fileSize"`
	FileType     string `json:"fileType"`
}

func parseFileMessage(raw any) (fileMessage, bool) {
	if raw == nil {
		return fileMessage{}, false
	}
	data, err := json.Marshal(raw) // Content arrives as map[string]any
	if err != nil {
		return fileMessage{}, false
	}
	var fm fileMessage
	if err := json.Unmarshal(data, &fm); err != nil || fm.DownloadCode == "" {
		return fileMessage{}, false
	}
	if fm.FileName == "" {
		fm.FileName = "upload." + fm.FileType
	}
	return fm, true
}

// messageFileDownloadURL exchanges a downloadCode for DingTalk's temporary URL
// (POST /v1.0/robot/messageFiles/download — verified live 2026-11-10).
func (c *openAPIClient) messageFileDownloadURL(ctx context.Context, downloadCode string) (string, error) {
	var out struct {
		DownloadURL string `json:"downloadUrl"`
	}
	err := c.doResult(ctx, http.MethodPost, "/v1.0/robot/messageFiles/download", map[string]string{
		"downloadCode": downloadCode,
		"robotCode":    c.appKey,
	}, &out)
	if err != nil {
		return "", err
	}
	if out.DownloadURL == "" {
		return "", fmt.Errorf("no downloadUrl in response")
	}
	return out.DownloadURL, nil
}

// pictureMessage is the Content payload of a msgtype="picture" callback. The
// exact field spelling varies across DingTalk app versions and cannot be
// verified offline (the SDK leaves Content raw) — accept every known one.
type pictureMessage struct {
	DownloadCode        string `json:"downloadCode"`
	PictureDownloadCode string `json:"pictureDownloadCode"`
	PictureDownloadURL  string `json:"pictureDownloadUrl"`
	DownloadURL         string `json:"downloadUrl"`
}

func parsePictureMessage(raw any) (pictureMessage, bool) {
	if raw == nil {
		return pictureMessage{}, false
	}
	data, err := json.Marshal(raw)
	if err != nil {
		return pictureMessage{}, false
	}
	var pm pictureMessage
	if err := json.Unmarshal(data, &pm); err != nil {
		return pictureMessage{}, false
	}
	ok := pm.DownloadCode != "" || pm.PictureDownloadCode != "" || pm.PictureDownloadURL != "" || pm.DownloadURL != ""
	if !ok {
		return pictureMessage{}, false
	}
	return pm, true
}

// downloadMessageFile exchanges a downloadCode and saves the upload into dir
// (caller deletes it when done); returns its local path.
func (b *Bot) downloadMessageFile(ctx context.Context, fm fileMessage, dir string) (string, error) {
	dlURL, err := b.api.messageFileDownloadURL(ctx, fm.DownloadCode)
	if err != nil {
		return "", fmt.Errorf("get download url: %w", err)
	}
	if !fileURLAllowed(dlURL) {
		return "", fmt.Errorf("download url rejected: %q", dlURL)
	}

	// Reject oversize UP FRONT: the 15MB LimitReader used to store a silently
	// truncated document and the agent summarized half a file as if whole.
	if fm.FileSize > MaxFileDownloadBytes {
		return "", fmt.Errorf("file too large (%d bytes, max %d) — compress or split it", fm.FileSize, MaxFileDownloadBytes)
	}
	if fm.FileSize <= 0 {
		return "", fmt.Errorf("file reports no size — download refused")
	}

	return b.downloadTo(ctx, dlURL, filepath.Base(fm.FileName), dir)
}

// downloadHTTPClient downloads DingTalk files with a hard timeout. The old
// http.DefaultClient has NO timeout — a hung download server stuck the
// goroutine forever while it held one of MaxConcurrentAgents sem slots;
// four hung downloads deadlocked the whole bot (audit 2026-11-12).
var downloadHTTPClient = &http.Client{Timeout: 60 * time.Second}

// downloadTo GETs dlURL and saves the bytes into dir under a randomized name
// carrying name. Callers run fileURLAllowed first and own any declared-size
// pre-check (picture payloads carry none); downloadTo enforces only the
// post-download cap.
func (b *Bot) downloadTo(ctx context.Context, dlURL, name, dir string) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, 90*time.Second) // belt-and-braces: client + ctx deadline
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, dlURL, nil)
	if err != nil {
		return "", err
	}
	resp, err := downloadHTTPClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("download file: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("download file: HTTP %d", resp.StatusCode)
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	// os.CreateTemp: two uploads named report.docx in one chat overwrote each
	// other mid-read.
	out, err := os.CreateTemp(dir, "up-*-"+filepath.Base(name)) // never trust a client path
	if err != nil {
		return "", err
	}
	path := out.Name()
	if _, err := io.Copy(out, io.LimitReader(resp.Body, MaxFileDownloadBytes+1)); err != nil {
		out.Close()
		_ = os.Remove(path)
		return "", err
	}
	if err := out.Close(); err != nil {
		_ = os.Remove(path)
		return "", err
	}
	if fi, err := os.Stat(path); err != nil || fi.Size() > MaxFileDownloadBytes {
		_ = os.Remove(path)
		return "", fmt.Errorf("file exceeds the %d byte limit", MaxFileDownloadBytes)
	}
	return path, nil
}

// pictureDownloadURL resolves a picture's download URL: a direct https URL
// from the payload when present (allowlist-checked like every download), else
// the downloadCode exchange (picture codes ride the same messageFiles API;
// if DingTalk ever refuses them, the direct-URL spelling covers the rest).
func (b *Bot) pictureDownloadURL(ctx context.Context, pm pictureMessage) (string, error) {
	for _, u := range []string{pm.PictureDownloadURL, pm.DownloadURL} {
		if u != "" && fileURLAllowed(u) {
			return u, nil
		}
	}
	code := pm.PictureDownloadCode
	if code == "" {
		code = pm.DownloadCode
	}
	if code == "" {
		return "", fmt.Errorf("picture message carries neither a download url nor a code")
	}
	dlURL, err := b.api.messageFileDownloadURL(ctx, code)
	if err != nil {
		return "", fmt.Errorf("get download url: %w", err)
	}
	if !fileURLAllowed(dlURL) {
		return "", fmt.Errorf("download url rejected: %q", dlURL)
	}
	return dlURL, nil
}

// fileURLAllowed guards against the API returning an unexpected host —
// https only: a plain-http download URL would carry the document in cleartext.
func fileURLAllowed(raw string) bool {
	u, err := url.Parse(raw)
	return err == nil && u.Scheme == "https" && u.Host != ""
}
