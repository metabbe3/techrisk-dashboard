package dingtalk

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
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
	for _, ok := range []string{"https://a.dingtalk.com/x", "https://files.aliyuncs.com/y"} { // official hosts; http is cleartext — rejected
		if !fileURLAllowed(ok) {
			t.Errorf("%q should be allowed", ok)
		}
	}
	for _, bad := range []string{"file:///etc/passwd", "ftp://a.com", "not a url", "", "http://a.com/x"} {
		if fileURLAllowed(bad) {
			t.Errorf("%q should be rejected", bad)
		}
	}
}

// Picture payloads vary across DingTalk app versions — every known spelling
// parses; an empty payload is not a picture.
func TestParsePictureMessage(t *testing.T) {
	cases := []struct {
		name    string
		content map[string]any
		want    pictureMessage
	}{
		{"pictureDownloadCode", map[string]any{"pictureDownloadCode": "pc1"}, pictureMessage{PictureDownloadCode: "pc1"}},
		{"downloadCode", map[string]any{"downloadCode": "dc1"}, pictureMessage{DownloadCode: "dc1"}},
		{"pictureDownloadUrl", map[string]any{"pictureDownloadUrl": "https://a/x.png"}, pictureMessage{PictureDownloadURL: "https://a/x.png"}},
		{"downloadUrl", map[string]any{"downloadUrl": "https://a/x.png"}, pictureMessage{DownloadURL: "https://a/x.png"}},
	}
	for _, c := range cases {
		pm, ok := parsePictureMessage(c.content)
		if !ok || pm != c.want {
			t.Errorf("%s: parse = %+v, %v; want %+v", c.name, pm, ok, c.want)
		}
	}
	if _, ok := parsePictureMessage(map[string]any{"foo": "bar"}); ok {
		t.Error("empty payload misdetected as picture")
	}
	if _, ok := parsePictureMessage(nil); ok {
		t.Error("nil content misdetected as picture")
	}
}

// pictureDownloadURL: https direct URLs pass through; plain http is refused;
// a bare code resolves through the messageFiles exchange.
func TestPictureDownloadURL(t *testing.T) {
	b := &Bot{}
	u, err := b.pictureDownloadURL(context.Background(), pictureMessage{DownloadURL: "https://files.example/x.png"})
	if err == nil || u != "" { // non-DingTalk host: refused since the allowlist (audit round 4)
		t.Fatalf("direct url: %q, %v", u, err)
	}
	if _, err := b.pictureDownloadURL(context.Background(), pictureMessage{DownloadURL: "https://static.dingtalk.com/x.png", DownloadCode: ""}); err != nil {
		t.Fatal("plain-http direct url accepted")
	}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/v1.0/oauth2/accessToken" {
			_, _ = w.Write([]byte(`{"accessToken":"tok","expireIn":3600}`))
			return
		}
		if r.URL.Path != "/v1.0/robot/messageFiles/download" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["downloadCode"] != "pc9" {
			t.Errorf("body = %v", body)
		}
		_, _ = w.Write([]byte(`{"downloadUrl":"https://files.dingtalk.com/y.png"}`))
	}))
	defer srv.Close()
	b = &Bot{api: &openAPIClient{appKey: "appkey", host: srv.URL, httpClient: srv.Client()}}
	u, err = b.pictureDownloadURL(context.Background(), pictureMessage{PictureDownloadCode: "pc9"})
	if err != nil || u != "https://files.dingtalk.com/y.png" {
		t.Fatalf("code branch: %q, %v", u, err)
	}
}

// downloadTo saves the bytes under dir; oversize is refused with nothing left.
func TestDownloadTo(t *testing.T) {
	dir := t.TempDir()
	payload := bytes.Repeat([]byte{0x89, 'P', 'N', 'G'}, 100)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write(payload)
	}))
	defer srv.Close()
	b := &Bot{}
	path, err := b.downloadTo(context.Background(), srv.URL, "picture.png", dir)
	if err != nil {
		t.Fatal(err)
	}
	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, payload) {
		t.Fatalf("saved %d bytes, sent %d", len(got), len(payload))
	}
	_ = os.Remove(path)

	big := bytes.Repeat([]byte{'x'}, MaxFileDownloadBytes+100)
	srv2 := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write(big)
	}))
	defer srv2.Close()
	path, err = b.downloadTo(context.Background(), srv2.URL, "big.bin", dir)
	if err == nil {
		t.Fatal("oversize download accepted")
	}
	if path != "" {
		_ = os.Remove(path)
		t.Fatal("oversize download left a file behind")
	}
}
