// reportpdf.go — generate_pdf_report: render agent-composed markdown to a
// styled A4 PDF. A deliverable capability scoped by the guardrails to
// dashboard content.
package tools

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/go-pdf/fpdf"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

const (
	maxReportMarkdown = 30000
	reportTopMargin   = 16
)

// GeneratedFileMarker wraps the relative path of a deliverable; the bot scans
// replies for it and ships the file to the sender's DM.
const GeneratedFileMarker = "[generated-file: %s]"

func (r *Registry) registerGenerateTools(cfg *config.Config) {
	dir := cfg.DataDir
	if dir == "" {
		dir = "data"
	}
	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "generate_pdf_report",
			Description: "Render a report to a styled A4 PDF file and send it to the user. Use for 'make me a PDF report of…' requests. Content must be composed from dashboard tool results — never fabricate data.",
			Parameters:  `{"type":"object","properties":{"title":{"type":"string","description":"Report title"},"markdown":{"type":"string","description":"Report body as markdown: # h1, ## h2, ### h3, - bullets, **bold**, plain paragraphs"}},"required":["title","markdown"]}`,
		},
	}, func(argsJSON string) (string, error) {
		return handleGeneratePDF(dir, argsJSON)
	})

	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "generate_deck",
			Description: "Render a slide deck to a PPTX file and send it to the user. Use for 'make me a deck / PPT / slides of…' requests. Content must be composed from dashboard tool results — never fabricate data.",
			// ponytail: schema stays minimal (no maxItems / nested required) — strict
			// upstream tool validation (TencentException, 2026-11-10) rejects exotic
			// keywords and 400s the WHOLE request; the 20-slide/15-bullet caps live
			// in renderDeck instead.
			Parameters: `{"type":"object","properties":{"title":{"type":"string","description":"Deck title"},"slides":{"type":"array","description":"Max 20 slides; each slide needs a heading, bullets are optional (max 15 each)","items":{"type":"object","properties":{"heading":{"type":"string"},"bullets":{"type":"array","items":{"type":"string"}}}}}},"required":["title","slides"]}`,
		},
	}, func(argsJSON string) (string, error) {
		return handleGenerateDeck(dir, argsJSON)
	})
}

// exportPath builds <dir>/exports/<stamp>-<slug>.<ext> under a safe name.
func exportPath(dir, title, ext string) (string, error) {
	slug := slugify(title)
	if slug == "" {
		slug = "report"
	}
	name := fmt.Sprintf("%s-%s.%s", time.Now().Format("20060102-150405"), slug, ext)
	sub := filepath.Join(dir, "exports")
	if err := os.MkdirAll(sub, 0o755); err != nil {
		return "", err
	}
	return filepath.Join(sub, name), nil
}

var unsafeName = regexp.MustCompile(`[^a-zA-Z0-9-]+`)

func slugify(title string) string {
	s := strings.ToLower(strings.TrimSpace(title))
	s = unsafeName.ReplaceAllString(s, "-")
	s = strings.Trim(s, "-")
	if len(s) > 60 {
		s = s[:60]
	}
	// filepath already constrains the name; the regexp guarantees no path bytes.
	return s
}

func handleGeneratePDF(dir, argsJSON string) (string, error) {
	var args struct {
		Title    string `json:"title"`
		Markdown string `json:"markdown"`
	}
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	if strings.TrimSpace(args.Markdown) == "" {
		return "Error: markdown body is empty — compose the report from tool results first.", nil
	}
	if len(args.Markdown) > maxReportMarkdown {
		args.Markdown = args.Markdown[:maxReportMarkdown]
	}
	title := orDefaultStr(args.Title, "Technical Risk Report")

	path, err := exportPath(dir, title, "pdf")
	if err != nil {
		return "", err
	}
	if err := renderMarkdownPDF(path, title, args.Markdown); err != nil {
		return "", err
	}
	rel, _ := filepath.Rel(dir, path)
	return fmt.Sprintf("PDF created: %s — tell the user it was generated and sent to their DM. Include this marker verbatim in your reply: "+GeneratedFileMarker, rel, rel), nil
}

func orDefaultStr(s, def string) string {
	if strings.TrimSpace(s) == "" {
		return def
	}
	return s
}

// renderMarkdownPDF is a tiny md→PDF renderer covering what incident reports
// need: h1/h2/h3, bullets, bold, paragraphs, page breaks.
// ponytail: hand-rolled markdown subset; swap for a full converter if reports
// ever need tables/images.
func renderMarkdownPDF(path, title, markdown string) error {
	pdf := fpdf.New("P", "mm", "A4", "")
	pdf.SetMargins(18, reportTopMargin, 18)
	pdf.SetAutoPageBreak(true, 18)
	pdf.AddPage()

	// Title block
	pdf.SetFont("Arial", "B", 18)
	pdf.SetTextColor(30, 41, 59)
	pdf.MultiCell(0, 9, sanitizePDF(title), "", "L", false)
	pdf.SetFont("Arial", "", 8)
	pdf.SetTextColor(100, 116, 139)
	pdf.Cell(0, 5, time.Now().Format("2 January 2006, 15:04")+" — Technical Risk Dashboard")
	pdf.Ln(9)

	pdf.SetTextColor(30, 41, 59)

	lines := strings.Split(markdown, "\n")
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		switch {
		case trimmed == "":
			pdf.Ln(2)
		case strings.HasPrefix(trimmed, "### "):
			pdf.SetFont("Arial", "B", 11)
			pdf.MultiCell(0, 5.5, sanitizePDF(trimmed[4:]), "", "L", false)
			pdf.Ln(1)
		case strings.HasPrefix(trimmed, "## "):
			pdf.Ln(2)
			pdf.SetFont("Arial", "B", 13)
			pdf.MultiCell(0, 6.5, sanitizePDF(trimmed[3:]), "", "L", false)
		case strings.HasPrefix(trimmed, "# "):
			pdf.Ln(2)
			pdf.SetFont("Arial", "B", 15)
			pdf.MultiCell(0, 7.5, sanitizePDF(trimmed[2:]), "", "L", false)
		case strings.HasPrefix(trimmed, "- ") || strings.HasPrefix(trimmed, "* "):
			pdf.SetFont("Arial", "", 10)
			x := pdf.GetX()
			pdf.Cell(4, 5, "•")
			pdf.SetX(x + 4)
			writeBoldAware(pdf, trimmed[2:])
		default:
			pdf.SetFont("Arial", "", 10)
			writeBoldAware(pdf, trimmed)
		}
	}
	return pdf.OutputFileAndClose(path)
}

// writeBoldAware writes text with **bold** segments emphasized, wrapping
// within the page width.
func writeBoldAware(pdf *fpdf.Fpdf, text string) {
	parts := boldSplit(sanitizePDF(text))
	pdf.SetFont("Arial", "", 10)
	for i, part := range parts {
		if i%2 == 1 {
			pdf.SetFont("Arial", "B", 10)
		} else {
			pdf.SetFont("Arial", "", 10)
		}
		pdf.MultiCell(0, 5, part, "", "L", false)
	}
	pdf.Ln(1)
}

// boldSplit splits "a **b** c" into ["a ", "b", " c"] (odd indexes bold).
func boldSplit(s string) []string {
	var out []string
	rest := s
	for {
		i := strings.Index(rest, "**")
		if i < 0 {
			out = append(out, rest)
			break
		}
		out = append(out, rest[:i])
		rest = rest[i+2:]
		j := strings.Index(rest, "**")
		if j < 0 {
			out = append(out, rest)
			break
		}
		out = append(out, rest[:j])
		rest = rest[j+2:]
	}
	if len(out) == 0 {
		out = []string{""}
	}
	return out
}

// sanitizePDF strips characters the core Arial font cannot render (emoji etc.).
var pdfUnsupported = regexp.MustCompile(`[^\p{Latin}\p{P}\p{N}\s€$%+\-—–…&/=<>#:();'"?!\[\]{}]`)

func sanitizePDF(s string) string {
	s = strings.Map(func(r rune) rune {
		if r == '\t' {
			return ' '
		}
		return r
	}, s)
	return pdfUnsupported.ReplaceAllString(s, "")
}
