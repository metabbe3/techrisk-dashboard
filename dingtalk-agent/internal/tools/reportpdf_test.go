package tools

import (
	"bytes"
	"compress/zlib"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestGeneratePDFTool(t *testing.T) {
	dir := t.TempDir()
	args, _ := json.Marshal(map[string]string{
		"title":    "October Incident Report",
		"markdown": "## Summary\n- 3 P1 incidents\n- MTTR **45** minutes\n\nRoot cause analysis follows.",
	})
	out, err := handleGeneratePDF(dir, string(args))
	if err != nil {
		t.Fatalf("handleGeneratePDF: %v", err)
	}
	if !strings.Contains(out, "[generated-file: exports/") {
		t.Errorf("marker missing from tool result: %s", out)
	}

	files, _ := filepath.Glob(filepath.Join(dir, "exports", "*.pdf"))
	if len(files) != 1 {
		t.Fatalf("expected 1 pdf under exports/, got %v", files)
	}
	if !strings.HasSuffix(files[0], "-october-incident-report.pdf") {
		t.Errorf("slugified name wrong: %s", filepath.Base(files[0]))
	}

	data, err := os.ReadFile(files[0])
	if err != nil {
		t.Fatalf("read pdf: %v", err)
	}
	if !strings.HasPrefix(string(data), "%PDF") {
		t.Errorf("not a PDF: %q", string(data[:8]))
	}
	if !strings.Contains(string(data), "%%EOF") {
		t.Errorf("PDF missing EOF marker")
	}
}

func TestGeneratePDFEmptyMarkdown(t *testing.T) {
	args, _ := json.Marshal(map[string]string{"title": "X", "markdown": "  "})
	out, err := handleGeneratePDF(t.TempDir(), string(args))
	// Empty markdown is a real error — Execute renders it as tool content.
	if err == nil || !strings.Contains(err.Error(), "empty") {
		t.Fatalf("empty markdown must error, got out=%q err=%v", out, err)
	}
}

func TestPDFRendersMarkdownConstructs(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "out.pdf")
	md := "# Heading One\n## Heading Two\n### Heading Three\n\n- bullet one\n- bullet two\n\nA paragraph with **bold** text and <angles> & ampersands."
	if err := renderMarkdownPDF(path, "Title & <Angles>", md); err != nil {
		t.Fatalf("renderMarkdownPDF: %v", err)
	}
	info, err := os.Stat(path)
	if err != nil {
		t.Fatalf("stat: %v", err)
	}
	if info.Size() < 1500 {
		t.Errorf("suspiciously small PDF (%d bytes) — content likely dropped", info.Size())
	}
}

func TestRenderMarkdownPDFWithTable(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "t.pdf")
	md := "# Report\n\n| Col A | Col B |\n|---|---|\n| **INC-1** | High |\n| INC-2 | Low |\n\nBody after table."
	if err := renderMarkdownPDF(path, "Table Test", md); err != nil {
		t.Fatalf("render: %v", err)
	}
	b, err := os.ReadFile(path)
	if err != nil || len(b) < 1000 {
		t.Fatalf("pdf too small: %d bytes, err=%v", len(b), err)
	}
	if string(b[:5]) != "%PDF-" {
		t.Fatalf("not a PDF: %q", string(b[:10]))
	}
}

func TestEnsureRenderableRejectsCJK(t *testing.T) {
	if err := ensureRenderable("plain english"); err != nil {
		t.Errorf("latin text should pass: %v", err)
	}
	if err := ensureRenderable("\u98ce\u9669\u62a5\u544a"); err == nil {
		t.Errorf("CJK text must be rejected, got nil")
	}
}

// Em dashes must reach the PDF as cp1252 \x97 (via the translator), not raw
// UTF-8 — un-translated, every viewer renders E2 80 94 as "â€"". The date
// line's suffix and bold-split segments both used to skip tr().
func TestPDFEmDashTranslatedNotMojibake(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "d.pdf")
	md := "A **bold — dash** paragraph.\n\nSecond line — with a dash."
	if err := renderMarkdownPDF(path, "Dash — Report", md); err != nil {
		t.Fatalf("renderMarkdownPDF: %v", err)
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var text []byte // all successfully inflated content streams, concatenated
	for i := 0; i+8 < len(data); {
		start := indexFrom(data, []byte("stream"), i)
		if start < 0 {
			break
		}
		end := indexFrom(data, []byte("endstream"), start)
		if end < 0 {
			break
		}
		body := data[start+len("stream") : end]
		body = bytes.TrimLeft(body, "\r\n")
		if zr, err := zlib.NewReader(bytes.NewReader(body)); err == nil {
			out, _ := io.ReadAll(zr)
			text = append(text, out...)
			zr.Close()
		}
		i = end + len("endstream")
	}
	if len(text) == 0 {
		t.Fatal("no inflateable content streams found — probe broken")
	}
	if bytes.Contains(text, []byte("\xe2\x80\x94")) {
		t.Error("raw UTF-8 em dash in the PDF stream — renders as mojibake")
	}
	if !bytes.Contains(text, []byte("\x97")) {
		t.Error("no cp1252 em dash in the PDF stream — the dash was dropped, not translated")
	}
}

func indexFrom(hay, needle []byte, from int) int {
	idx := bytes.Index(hay[from:], needle)
	if idx < 0 {
		return -1
	}
	return from + idx
}
