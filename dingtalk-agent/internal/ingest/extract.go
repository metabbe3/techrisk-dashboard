// Package ingest extracts plain text from chat-uploaded document formats so
// the LLM can summarize them. Nothing here touches disk — callers own the file
// lifecycle (download, delete-after-done).
package ingest

import (
	"archive/zip"
	"bytes"
	"fmt"
	"io"
	"path"
	"regexp"
	"strings"

	pdf "github.com/ledongthuc/pdf"
)

// MaxExtractChars caps what is fed to the LLM from one file.
const MaxExtractChars = 48_000

var tagRe = regexp.MustCompile(`<[^>]*>`)

// ExtractText returns the textual content of a document by filename
// extension. Supported: .txt .md .csv .json .log .xml (plain), .docx, .pdf.
func ExtractText(filename string, data []byte) (string, error) {
	switch strings.ToLower(path.Ext(filename)) {
	case ".txt", ".md", ".csv", ".json", ".log", ".xml", "":
		return string(data), nil
	case ".docx":
		return extractDocx(data)
	case ".pdf":
		return extractPdf(data)
	default:
		return "", fmt.Errorf("unsupported file type %q (supported: txt, md, csv, json, log, xml, docx, pdf)", path.Ext(filename))
	}
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
		// Paragraph boundaries become newlines, then drop every tag.
		withBreaks := strings.ReplaceAll(string(xml), "</w:p>", "\n")
		text := tagRe.ReplaceAllString(withBreaks, "")
		return strings.TrimSpace(text), nil
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
		return "", fmt.Errorf("no extractable text in pdf (scanned/image pdfs are not supported)")
	}
	return out, nil
}

// Truncate clamps extracted text to MaxExtractChars with an explicit marker.
func Truncate(s string) string {
	if len(s) <= MaxExtractChars {
		return s
	}
	return s[:MaxExtractChars] + "\n…[truncated]"
}
