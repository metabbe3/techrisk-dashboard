package ingest

import (
	"archive/zip"
	"bytes"
	"strings"
	"testing"

	"github.com/xuri/excelize/v2"
)

func TestExtractXlsxSheetsAndCells(t *testing.T) {
	f := excelize.NewFile()
	if err := f.SetSheetName("Sheet1", "Incidents"); err != nil {
		t.Fatal(err)
	}
	if err := f.SetCellValue("Incidents", "A1", "INC-001"); err != nil {
		t.Fatal(err)
	}
	if err := f.SetCellValue("Incidents", "B1", "High"); err != nil {
		t.Fatal(err)
	}
	var buf strings.Builder
	if err := f.Write(&buf); err != nil {
		t.Fatal(err)
	}
	out, err := extractXlsx([]byte(buf.String()))
	if err != nil {
		t.Fatalf("extractXlsx: %v", err)
	}
	if !strings.Contains(out, "Sheet: Incidents") || !strings.Contains(out, "INC-001\tHigh") {
		t.Errorf("unexpected output: %q", out)
	}
}

func TestExtractDocxTableCellsGetSeparators(t *testing.T) {
	// Build a minimal docx zip with one table row of two cells.
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	w, _ := zw.Create("word/document.xml")
	w.Write([]byte("<w:document><w:body><w:tbl><w:tr><w:tc><w:p>Alpha</w:p></w:tc><w:tc><w:p>Beta</w:p></w:tc></w:tr></w:tbl></w:body></w:document>"))
	zw.Close()
	out, err := extractDocx(buf.Bytes())
	if err != nil {
		t.Fatalf("extractDocx: %v", err)
	}
	if !strings.Contains(out, "Alpha \tBeta") {
		t.Errorf("table cells not tab-separated: %q", out)
	}
}
