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

// downloadMessageFile saves the upload into dir (caller deletes it when done)
// and returns its local path.
func (b *Bot) downloadMessageFile(ctx context.Context, fm fileMessage, dir string) (string, error) {
	dlURL, err := b.api.messageFileDownloadURL(ctx, fm.DownloadCode)
	if err != nil {
		return "", fmt.Errorf("get download url: %w", err)
	}
	if !fileURLAllowed(dlURL) {
		return "", fmt.Errorf("download url rejected: %q", dlURL)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, dlURL, nil)
	if err != nil {
		return "", err
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("download file: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("download file: HTTP %d", resp.StatusCode)
	}

	// Reject oversize UP FRONT: the 15MB LimitReader used to store a silently
	// truncated document and the agent summarized half a file as if whole.
	if fm.FileSize > MaxFileDownloadBytes {
		return "", fmt.Errorf("file too large (%d bytes, max %d) — compress or split it", fm.FileSize, MaxFileDownloadBytes)
	}
	if fm.FileSize <= 0 {
		return "", fmt.Errorf("file reports no size — download refused")
	}

	name := filepath.Base(fm.FileName) // never trust a client path
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	// os.CreateTemp: two uploads named report.docx in one chat overwrote each
	// other mid-read.
	out, err := os.CreateTemp(dir, "up-*-"+name)
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

// fileURLAllowed guards against the API returning an unexpected host —
// https only: a plain-http download URL would carry the document in cleartext.
func fileURLAllowed(raw string) bool {
	u, err := url.Parse(raw)
	return err == nil && u.Scheme == "https" && u.Host != ""
}
