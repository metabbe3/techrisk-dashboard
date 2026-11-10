package tools

import (
	"encoding/json"
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
	if err != nil {
		t.Fatalf("handleGeneratePDF: %v", err)
	}
	if !strings.Contains(out, "Error") {
		t.Errorf("empty markdown should return an error note, got: %s", out)
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
