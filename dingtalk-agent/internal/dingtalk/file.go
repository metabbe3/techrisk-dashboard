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

	name := filepath.Base(fm.FileName) // never trust a client path
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	path := filepath.Join(dir, name)
	out, err := os.Create(path)
	if err != nil {
		return "", err
	}
	defer out.Close()
	if _, err := io.Copy(out, io.LimitReader(resp.Body, MaxFileDownloadBytes)); err != nil {
		_ = os.Remove(path)
		return "", err
	}
	return path, nil
}

// fileURLWhitelisted guards against the API returning an unexpected host.
func fileURLAllowed(raw string) bool {
	u, err := url.Parse(raw)
	return err == nil && (u.Scheme == "https" || u.Scheme == "http") && u.Host != ""
}
