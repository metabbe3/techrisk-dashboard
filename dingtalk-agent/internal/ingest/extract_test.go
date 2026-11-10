package ingest

import (
	"archive/zip"
	"bytes"
	"strings"
	"testing"
)

func TestExtractTextPlain(t *testing.T) {
	out, err := ExtractText("notes.txt", []byte("hello risk"))
	if err != nil || out != "hello risk" {
		t.Fatalf("txt: %q, %v", out, err)
	}
	out, err = ExtractText("report.md", []byte("# hi"))
	if err != nil || out != "# hi" {
		t.Fatalf("md: %q, %v", out, err)
	}
}

func TestExtractDocx(t *testing.T) {
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	w, _ := zw.Create("word/document.xml")
	w.Write([]byte(`<w:document><w:body><w:p>Hello <w:r><w:t>risk</w:t></w:r></w:p><w:p>Second para</w:p></w:body></w:document>`))
	zw.Close()

	out, err := ExtractText("doc.docx", buf.Bytes())
	if err != nil {
		t.Fatalf("docx: %v", err)
	}
	if out != "Hello risk\nSecond para" {
		t.Fatalf("docx text = %q", out)
	}
}

func TestExtractUnsupported(t *testing.T) {
	if _, err := ExtractText("virus.exe", nil); err == nil {
		t.Fatal("expected error for unsupported extension")
	}
}

func TestTruncate(t *testing.T) {
	if got := Truncate(strings.Repeat("a", MaxExtractChars+5)); !strings.HasSuffix(got, "…[truncated]") {
		t.Fatal("truncation marker missing")
	}
	if got := Truncate("short"); got != "short" {
		t.Fatalf("short text altered: %q", got)
	}
}
