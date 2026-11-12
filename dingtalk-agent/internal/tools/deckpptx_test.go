package tools

import (
	"archive/zip"
	"encoding/json"
	"encoding/xml"
	"io"
	"path/filepath"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/kb"
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
	args, _ := json.Marshal(map[string]string{
		"title":  "Severity Breakdown 2026",
		"slides": "## Overview & <Totals>\n- 12 P1\n- 30 P2\n\n## Trends\n- MTTR improving\n\n## Actions",
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
	args, _ := json.Marshal(map[string]string{"title": "X", "slides": "   "})
	out, err := handleGenerateDeck(t.TempDir(), string(args))
	if err != nil {
		t.Fatalf("handleGenerateDeck: %v", err)
	}
	if !strings.Contains(out, "Error") {
		t.Errorf("empty slides should return an error note, got: %s", out)
	}
}

// TestPptxWellFormed: every XML part unmarshals (well-formedness + declared
// namespaces) — PowerPoint answers a malformed part with a "repair" dialog.
// Also pins the p:-prefixed presentation elements (sldMasterIdLst/sldIdLst/
// sldSz/notesSz shipped unprefixed once and PowerPoint refused the file).
func TestPptxWellFormed(t *testing.T) {
	dir := t.TempDir()
	args, _ := json.Marshal(map[string]string{
		"title": "WF", "slides": "## One & <Two>\n- bullet <1>\n- bullet 2\n",
	})
	if _, err := handleGenerateDeck(dir, string(args)); err != nil {
		t.Fatalf("generate: %v", err)
	}
	files, _ := filepath.Glob(filepath.Join(dir, "exports", "*.pptx"))
	z := readZip(t, files[0])

	for name, content := range z {
		if !strings.HasSuffix(name, ".xml") && !strings.HasSuffix(name, ".rels") {
			continue
		}
		var v any
		if err := xml.Unmarshal([]byte(content), &v); err != nil {
			t.Errorf("part %s not well-formed: %v", name, err)
		}
		if strings.Contains(content, "\\n") {
			t.Errorf("part %s contains a literal backslash-n", name)
		}
	}
	pres := z["ppt/presentation.xml"]
	for _, want := range []string{"<p:sldMasterIdLst>", "</p:sldMasterIdLst>", "<p:sldIdLst>", "</p:sldIdLst>", "<p:sldSz ", "<p:notesSz "} {
		if !strings.Contains(pres, want) {
			t.Errorf("presentation.xml missing %s — PowerPoint will ask to repair", want)
		}
	}
}

func TestParseDeckSpec(t *testing.T) {
	slides := parseDeckSpec("## Root Causes\n- pool exhausted\n- retry storm\n\nBare Heading\n- one\nplain line as bullet\n\n### Actions")
	if len(slides) != 3 {
		t.Fatalf("got %d slides, want 3: %+v", len(slides), slides)
	}
	if slides[0].Heading != "Root Causes" || len(slides[0].Bullets) != 2 {
		t.Errorf("slide0 wrong: %+v", slides[0])
	}
	if slides[1].Heading != "Bare Heading" || len(slides[1].Bullets) != 2 {
		t.Errorf("slide1 wrong: %+v", slides[1])
	}
	if slides[2].Heading != "Actions" || len(slides[2].Bullets) != 0 {
		t.Errorf("slide2 wrong: %+v", slides[2])
	}
	if parseDeckSpec("  \n\n") != nil {
		t.Errorf("whitespace-only spec must parse to nil")
	}
}

// TestAllToolSchemasFlat pins every registered tool's parameters schema to the
// flat subset strict upstream validators accept (string/number/integer/
// boolean properties with type+description only), AND to the object wire-form.
// The 2026-11-10 prod outage was a raw-string Parameters: it double-encodes to
// "parameters":"{…}" and the upstream 400s the WHOLE chat request.
func TestAllToolSchemasFlat(t *testing.T) {
	reg := NewRegistry(&config.Config{WebToolsEnabled: true, NotionToolsEnabled: true})
	// The four KB tools ride the same guard — a nested schema 400s the WHOLE
	// upstream request (2026-11-10 outage); they shipped outside this net once.
	reg.RegisterKnowledgeTools(kb.NewIndex(t.TempDir(), "m", sameEmb{}))
	defs := reg.GetDefinitions()
	if len(defs) < 15 { // 3 dashboard + 2 web + 2 generate + 4 notion + 4 kb
		t.Fatalf("expected the full fleet, got %d defs", len(defs))
	}
	for _, def := range defs {
		// wire-form: parameters must marshal as a JSON object, never a string
		fnJSON, err := json.Marshal(def.Function)
		if err != nil {
			t.Fatalf("%s: cannot marshal function def: %v", def.Function.Name, err)
		}
		var fn struct {
			Parameters json.RawMessage `json:"parameters"`
		}
		if err := json.Unmarshal(fnJSON, &fn); err != nil || len(fn.Parameters) == 0 || fn.Parameters[0] != '{' {
			t.Errorf("%s: parameters must be a JSON object on the wire, got: %s", def.Function.Name, fn.Parameters)
			continue
		}
		var raw []byte
		switch v := def.Function.Parameters.(type) {
		case string: // tolerated for content validation; the wire check above still rejects it
			raw = []byte(v)
		default:
			var err error
			if raw, err = json.Marshal(v); err != nil {
				t.Fatalf("%s: cannot marshal parameters: %v", def.Function.Name, err)
			}
		}
		var sch struct {
			Type       string         `json:"type"`
			Properties map[string]any `json:"properties"`
		}
		if err := json.Unmarshal(raw, &sch); err != nil {
			t.Fatalf("%s: parameters not valid JSON: %v", def.Function.Name, err)
		}
		if sch.Type != "object" {
			t.Errorf("%s: top-level type %q, want object", def.Function.Name, sch.Type)
		}
		for name, p := range sch.Properties {
			pm, ok := p.(map[string]any)
			if !ok {
				t.Errorf("%s.%s: property is not an object", def.Function.Name, name)
				continue
			}
			switch pm["type"] {
			case "string", "number", "integer", "boolean":
			default:
				t.Errorf("%s.%s: type %v — nested/non-flat types 400 the whole request on strict upstreams", def.Function.Name, name, pm["type"])
			}
			for k := range pm {
				if k != "type" && k != "description" {
					t.Errorf("%s.%s: keyword %q not allowed (keep schemas flat)", def.Function.Name, name, k)
				}
			}
		}
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
