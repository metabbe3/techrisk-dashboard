package dingtalk

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestParseFileMessage(t *testing.T) {
	fm, ok := parseFileMessage(map[string]any{
		"downloadCode": "dc123",
		"fileName":     "incident report.docx",
		"fileSize":     float64(1024),
		"fileType":     "docx",
	})
	if !ok || fm.DownloadCode != "dc123" || fm.FileName != "incident report.docx" {
		t.Fatalf("parse = %+v, %v", fm, ok)
	}

	// No downloadCode (e.g. a text message) → not a file message.
	if _, ok := parseFileMessage(map[string]any{"content": "hello"}); ok {
		t.Fatal("text message misdetected as file")
	}
	if _, ok := parseFileMessage(nil); ok {
		t.Fatal("nil content misdetected as file")
	}

	// Missing fileName falls back to the fileType.
	fm, ok = parseFileMessage(map[string]any{"downloadCode": "dc", "fileType": "pdf"})
	if !ok || fm.FileName != "upload.pdf" {
		t.Fatalf("filename fallback = %q, %v", fm.FileName, ok)
	}
}

func TestMessageFileDownloadURL(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/v1.0/oauth2/accessToken" {
			w.Write([]byte(`{"accessToken":"tok","expireIn":3600}`))
			return
		}
		if r.URL.Path != "/v1.0/robot/messageFiles/download" || r.Method != http.MethodPost {
			t.Errorf("unexpected %s %s", r.Method, r.URL.Path)
		}
		var body map[string]string
		json.NewDecoder(r.Body).Decode(&body)
		if body["downloadCode"] != "dc" || body["robotCode"] != "appkey" {
			t.Errorf("body = %v", body)
		}
		w.Write([]byte(`{"downloadUrl":"https://files.example/x.docx"}`))
	}))
	defer srv.Close()

	c := &openAPIClient{appKey: "appkey", host: srv.URL, httpClient: srv.Client()}
	url, err := c.messageFileDownloadURL(context.Background(), "dc")
	if err != nil || url != "https://files.example/x.docx" {
		t.Fatalf("url = %q, %v", url, err)
	}
}

func TestFileURLAllowed(t *testing.T) {
	for _, ok := range []string{"https://a.com/x", "http://a.com/x"} {
		if !fileURLAllowed(ok) {
			t.Errorf("%q should be allowed", ok)
		}
	}
	for _, bad := range []string{"file:///etc/passwd", "ftp://a.com", "not a url", ""} {
		if fileURLAllowed(bad) {
			t.Errorf("%q should be rejected", bad)
		}
	}
}
