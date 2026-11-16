// Package ingest extracts plain text from chat-uploaded document formats so
// the LLM can summarize them. Nothing here touches disk — callers own the file
// lifecycle (download, delete-after-done).
package ingest

import (
	"archive/zip"
	"bytes"
	"fmt"
	"html"
	"io"
	"path"
	"regexp"
	"strings"

	pdf "github.com/ledongthuc/pdf"
	"github.com/xuri/excelize/v2"

	"techrisk-dashboard/dingtalk-agent/internal/util"
)

// MaxExtractChars caps what is fed to the LLM from one file.
const MaxExtractChars = 48_000

var tagRe = regexp.MustCompile(`<[^>]*>`)

// tableCellBreaks: a newline directly followed by the next cell's tab —
// i.e. the last paragraph break inside a cell — becomes a space.
// (Go regexp has no lookahead; implemented in collapseCellBreaks instead.)
func collapseCellBreaks(s string) string {
	for strings.Contains(s, "\n\t") {
		s = strings.ReplaceAll(s, "\n\t", " \t")
	}
	return s
}

// ExtractText returns the textual content of a document by filename
// extension. Supported: .txt .md .csv .json .log .xml (plain), .docx, .xlsx, .pdf.
func ExtractText(filename string, data []byte) (string, error) {
	switch strings.ToLower(path.Ext(filename)) {
	case ".txt", ".md", ".csv", ".json", ".log", ".xml", "":
		return string(data), nil
	case ".docx":
		return extractDocx(data)
	case ".xlsx":
		return extractXlsx(data)
	case ".pdf":
		return extractPdf(data)
	default:
		return "", fmt.Errorf("unsupported file type %q (supported: txt, md, csv, json, log, xml, docx, xlsx, pdf)", path.Ext(filename))
	}
}

// extractXlsx reads every sheet row-by-row ("Sheet: name" headers, tab-separated
// cells, empty rows dropped). Excel is the most common incident attachment in
// risk ops and used to hard-fail as "unsupported" (audit 2026-11-12).
func extractXlsx(data []byte) (string, error) {
	f, err := excelize.OpenReader(bytes.NewReader(data))
	if err != nil {
		return "", fmt.Errorf("open xlsx: %w", err)
	}
	defer f.Close()
	var sb strings.Builder
	// Streaming row iterator with hard caps: GetRows materialized the WHOLE
	// sheet into memory — a sparse sheet with millions of empty rows could
	// exhaust the heap (audit round 4).
	const maxRowsPerSheet = 5000
	for _, sheet := range f.GetSheetList() {
		iter, err := f.Rows(sheet)
		if err != nil {
			continue // unreadable sheet: keep the rest
		}
		fmt.Fprintf(&sb, "Sheet: %s\n", sheet)
		n := 0
		for iter.Next() {
			if n >= maxRowsPerSheet {
				fmt.Fprintf(&sb, "...[sheet truncated at %d rows]\n", maxRowsPerSheet)
				break
			}
			row, _ := iter.Columns()
			joined := strings.TrimRight(strings.Join(row, "	"), "	")
			if strings.TrimSpace(joined) == "" {
				continue
			}
			sb.WriteString(joined)
			sb.WriteByte('\n')
			if sb.Len() > MaxExtractChars {
				fmt.Fprintf(&sb, "...[extraction capped at %d chars]\n", MaxExtractChars)
				return strings.TrimSpace(sb.String()), nil
			}
			n++
		}
		sb.WriteByte('\n')
	}
	out := strings.TrimSpace(sb.String())
	if out == "" {
		return "", fmt.Errorf("xlsx has no readable cells (sheet may be charts-only)")
	}
	return out, nil
}

// extractDocx pulls word/document.xml out of the OOXML zip and strips tags.
// ponytail: crude tag-strip, fine for text summarization; loses tables' layout.
func extractDocx(data []byte) (string, error) {
	zr, err := zip.NewReader(bytes.NewReader(data), int64(len(data)))
	if err != nil {
		return "", fmt.Errorf("open docx: %w", err)
	}
	for _, f := range zr.File {
		if f.Name != "word/document.xml" {
			continue
		}
		rc, err := f.Open()
		if err != nil {
			return "", err
		}
		defer rc.Close()
		xml, err := io.ReadAll(io.LimitReader(rc, 20<<20))
		if err != nil {
			return "", err
		}
		// Paragraph boundaries become newlines, table cells become tab-
		// separated rows (a raw cell strip used to glue "Cell1Cell2" together),
		// then drop every tag and resolve XML entities — "&amp;" used to land
		// in the prompt raw.
		withBreaks := strings.ReplaceAll(string(xml), "</w:p>", "\n")
		withBreaks = strings.ReplaceAll(withBreaks, "</w:tc>", "	")
		withBreaks = strings.ReplaceAll(withBreaks, "</w:tr>", "\n")
		text := tagRe.ReplaceAllString(withBreaks, "")
		// Inside a table cell, paragraph breaks collapse to a space so the
		// row stays one tab-separated line ("Alpha\n	Beta" → "Alpha Beta	").
		text = collapseCellBreaks(text)
		return strings.TrimSpace(html.UnescapeString(text)), nil
	}
	return "", fmt.Errorf("docx has no word/document.xml")
}

func extractPdf(data []byte) (string, error) {
	r, err := pdf.NewReader(bytes.NewReader(data), int64(len(data)))
	if err != nil {
		return "", fmt.Errorf("open pdf: %w", err)
	}
	var sb strings.Builder
	for i := 1; i <= r.NumPage(); i++ {
		page := r.Page(i)
		if page.V.IsNull() {
			continue
		}
		txt, err := page.GetPlainText(nil)
		if err != nil {
			continue // skip unreadable pages, keep the rest
		}
		sb.WriteString(txt)
		sb.WriteString("\n")
	}
	out := strings.TrimSpace(sb.String())
	if out == "" {
		// Scanned/image-only PDF parses but has no text layer — empty text,
		// not an error; the caller owns the "send pages as pictures" message.
		// ponytail: scanned PDFs out of scope; OCR or per-page image fallback if this bites.
		return "", nil
	}
	return out, nil
}

// Truncate clamps extracted text to MaxExtractChars with an explicit marker.
func Truncate(s string) string {
	if len(s) <= MaxExtractChars {
		return s
	}
	return util.Cut(s, MaxExtractChars) + "\n…[truncated]"
}
