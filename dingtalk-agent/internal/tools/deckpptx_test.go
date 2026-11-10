package tools

import (
	"archive/zip"
	"encoding/json"
	"io"
	"path/filepath"
	"strings"
	"testing"
)

func readZip(t *testing.T, path string) map[string]string {
	t.Helper()
	r, err := zip.OpenReader(path)
	if err != nil {
		t.Fatalf("open pptx: %v", err)
	}
	defer r.Close()
	files := map[string]string{}
	for _, f := range r.File {
		rc, err := f.Open()
		if err != nil {
			t.Fatalf("open %s: %v", f.Name, err)
		}
		data, _ := io.ReadAll(rc)
		rc.Close()
		files[f.Name] = string(data)
	}
	return files
}

func TestGenerateDeckTool(t *testing.T) {
	dir := t.TempDir()
	args, _ := json.Marshal(map[string]any{
		"title": "Severity Breakdown 2026",
		"slides": []map[string]any{
			{"heading": "Overview & <Totals>", "bullets": []string{"12 P1", "30 P2"}},
			{"heading": "Trends", "bullets": []string{"MTTR improving"}},
			{"heading": "Actions"},
		},
	})
	out, err := handleGenerateDeck(dir, string(args))
	if err != nil {
		t.Fatalf("handleGenerateDeck: %v", err)
	}
	if !strings.Contains(out, "[generated-file: exports/") {
		t.Errorf("marker missing from tool result: %s", out)
	}

	files, _ := filepath.Glob(filepath.Join(dir, "exports", "*.pptx"))
	if len(files) != 1 {
		t.Fatalf("expected 1 pptx under exports/, got %v", files)
	}
	z := readZip(t, files[0])

	for _, name := range []string{
		"[Content_Types].xml", "_rels/.rels", "ppt/presentation.xml",
		"ppt/_rels/presentation.xml.rels", "ppt/slideMasters/slideMaster1.xml",
		"ppt/slideLayouts/slideLayout1.xml", "ppt/theme/theme1.xml",
		"ppt/slides/slide1.xml", "ppt/slides/slide2.xml", "ppt/slides/slide3.xml",
		"ppt/slides/_rels/slide1.xml.rels",
	} {
		if _, ok := z[name]; !ok {
			t.Errorf("pptx missing part %s", name)
		}
	}
	if got := strings.Count(z["ppt/presentation.xml"], "<p:sldId "); got != 3 {
		t.Errorf("presentation lists %d slides, want 3", got)
	}
	if !strings.Contains(z["ppt/slides/slide1.xml"], "Overview &amp; &lt;Totals&gt;") {
		t.Errorf("slide1 heading not XML-escaped: %.300s", z["ppt/slides/slide1.xml"])
	}
	if !strings.Contains(z["ppt/slides/slide1.xml"], "12 P1") || !strings.Contains(z["ppt/slides/slide1.xml"], "•") {
		t.Errorf("slide1 bullets missing: %.300s", z["ppt/slides/slide1.xml"])
	}
	if strings.Contains(z["ppt/slides/slide3.xml"], "<a:buChar") {
		t.Errorf("slide3 should have no bullets, only a heading")
	}
	if n := strings.Count(z["[Content_Types].xml"], "slides/slide"); n != 3 {
		t.Errorf("content types lists %d slides, want 3", n)
	}
}

func TestGenerateDeckEmpty(t *testing.T) {
	args, _ := json.Marshal(map[string]any{"title": "X", "slides": []map[string]any{}})
	out, err := handleGenerateDeck(t.TempDir(), string(args))
	if err != nil {
		t.Fatalf("handleGenerateDeck: %v", err)
	}
	if !strings.Contains(out, "Error") {
		t.Errorf("empty slides should return an error note, got: %s", out)
	}
}

func TestDeckSlideCap(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "cap.pptx")
	slides := make([]deckSlide, 25)
	for i := range slides {
		slides[i] = deckSlide{Heading: "S"}
	}
	if err := renderDeck(path, "Cap", slides); err != nil {
		t.Fatalf("renderDeck: %v", err)
	}
	z := readZip(t, path)
	if _, ok := z["ppt/slides/slide21.xml"]; ok {
		t.Errorf("slide cap not enforced: slide21 exists")
	}
	if _, ok := z["ppt/slides/slide20.xml"]; !ok {
		t.Errorf("cap should keep 20 slides, slide20 missing")
	}
}
