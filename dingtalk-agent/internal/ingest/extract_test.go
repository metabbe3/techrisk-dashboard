package ingest

import (
	"archive/zip"
	"bytes"
	"fmt"
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

// A scanned/image-only PDF parses fine but yields zero text — that must come
// back as empty text (the caller owns the "send pages as pictures" message),
// not as a technical error.
func TestExtractScannedPdfReturnsEmptyNotError(t *testing.T) {
	out, err := ExtractText("scan.pdf", blankPdf())
	if err != nil {
		t.Fatalf("scanned pdf must return empty text, not an error: %v", err)
	}
	if strings.TrimSpace(out) != "" {
		t.Fatalf("scanned pdf text = %q, want empty", out)
	}
}

// blankPdf builds a valid one-page PDF with no text content and a correct
// cross-reference table (offsets computed, not hardcoded).
func blankPdf() []byte {
	objs := []string{
		"<< /Type /Catalog /Pages 2 0 R >>",
		"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
		"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>",
	}
	var buf bytes.Buffer
	buf.WriteString("%PDF-1.4\n")
	offsets := make([]int, len(objs)+1)
	for i, o := range objs {
		offsets[i+1] = buf.Len()
		fmt.Fprintf(&buf, "%d 0 obj\n%s\nendobj\n", i+1, o)
	}
	xref := buf.Len()
	fmt.Fprintf(&buf, "xref\n0 %d\n", len(objs)+1)
	buf.WriteString("0000000000 65535 f \n")
	for i := 1; i <= len(objs); i++ {
		fmt.Fprintf(&buf, "%010d 00000 n \n", offsets[i])
	}
	fmt.Fprintf(&buf, "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", len(objs)+1, xref)
	return buf.Bytes()
}

func TestTruncate(t *testing.T) {
	if got := Truncate(strings.Repeat("a", MaxExtractChars+5)); !strings.HasSuffix(got, "…[truncated]") {
		t.Fatal("truncation marker missing")
	}
	if got := Truncate("short"); got != "short" {
		t.Fatalf("short text altered: %q", got)
	}
}
