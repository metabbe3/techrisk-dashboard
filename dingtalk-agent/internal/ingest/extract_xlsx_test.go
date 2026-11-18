package ingest

import (
	"archive/zip"
	"bytes"
	"fmt"
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

// A blank-row-heavy sheet used to iterate past the cap forever: the 5,000 cap
// counted CONTENT rows only, so 10M blank rows burned 10M Columns() calls
// before any content. Every scanned row now counts toward a scanned cap.
func TestExtractXlsxBlankRowsBounded(t *testing.T) {
	oldScanned := maxScannedRowsPerSheet
	maxScannedRowsPerSheet = 100
	defer func() { maxScannedRowsPerSheet = oldScanned }()

	f := excelize.NewFile()
	for i := 1; i <= 150; i++ {
		if err := f.SetCellValue("Sheet1", fmt.Sprintf("A%d", i), ""); err != nil {
			t.Fatal(err)
		}
	}
	if err := f.SetCellValue("Sheet1", "A151", "late data"); err != nil {
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
	if !strings.Contains(out, "[sheet truncated at 100 rows]") {
		t.Errorf("scanned-row cap did not fire: %q", out)
	}
}

// The unzip limit must be set at open time — excelize's default is 16 GB, so
// a compressed workbook bomb expanded unbounded before the row caps ever ran.
func TestExtractXlsxUnzipLimitWired(t *testing.T) {
	old := xlsxUnzipSizeLimit
	xlsxUnzipSizeLimit = 200 // bytes: any real workbook exceeds it
	defer func() { xlsxUnzipSizeLimit = old }()

	f := excelize.NewFile()
	if err := f.SetCellValue("Sheet1", "A1", "x"); err != nil {
		t.Fatal(err)
	}
	var buf strings.Builder
	if err := f.Write(&buf); err != nil {
		t.Fatal(err)
	}
	_, err := extractXlsx([]byte(buf.String()))
	if err == nil || !strings.Contains(err.Error(), "unzip size") {
		t.Errorf("unzip limit not enforced at open time: %v", err)
	}
}
