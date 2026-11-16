// reportpdf.go — generate_pdf_report: render agent-composed markdown to a
// styled A4 PDF. A deliverable capability scoped by the guardrails to
// dashboard content.
package tools

import (
	"context"
	"crypto/rand"
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
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
	"techrisk-dashboard/dingtalk-agent/internal/util"
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
	// Schema literals ride through schema() — a raw string marshals as
	// "parameters":"{...}" and the upstream 400s the WHOLE request
	// (live-bisected against the gateway, 2026-11-10).
	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "generate_pdf_report",
			Description: "Render a report to a styled A4 PDF file and send it to the user. Use for 'make me a PDF report of…' requests. Content must be composed from dashboard tool results — never fabricate data.",
			Parameters: schema(
				map[string]any{
					"title":    map[string]any{"type": "string", "description": "Report title"},
					"markdown": map[string]any{"type": "string", "description": "Report body as markdown: # h1, ## h2, ### h3, - bullets, **bold**, plain paragraphs"},
				},
				[]string{"title", "markdown"},
			),
		},
	}, func(ctx context.Context, argsJSON string) (string, error) {
		return handleGeneratePDF(dir, argsJSON)
	})

	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "generate_deck",
			Description: "Render a slide deck to a PPTX file and send it to the user. Use for 'make me a deck / PPT / slides of…' requests. Content must be composed from dashboard tool results — never fabricate data.",
			Parameters: schema(
				map[string]any{
					"title":  map[string]any{"type": "string", "description": "Deck title"},
					"slides": map[string]any{"type": "string", "description": "Slides as markdown, blocks separated by blank lines. First line of each block (## Heading or plain text) is the slide heading; following '- ' lines are its bullets. Max 20 slides, 15 bullets each."},
				},
				[]string{"title", "slides"},
			),
		},
	}, func(ctx context.Context, argsJSON string) (string, error) {
		return handleGenerateDeck(dir, argsJSON)
	})
}

// exportPath builds <dir>/exports/<stamp>-<slug>.<ext> under a safe name.
func exportPath(dir, title, ext string) (string, error) {
	slug := slugify(title)
	if slug == "" {
		slug = "report"
	}
	// WIB clock + a random suffix — two parallel tool calls in the same second
	// used to overwrite each other's file.
	name := fmt.Sprintf("%s-%s-%s.%s", time.Now().In(schedule.WIB).Format("20060102-150405"), util.Cut(rand.Text(), 6), slug, ext)
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
	// filepath already constrains the name; the regexp guarantees no path bytes.
	return util.Cut(s, 60)
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
		return "", fmt.Errorf("markdown body is empty — compose the report from tool results first")
	}
	args.Markdown = util.Cut(args.Markdown, maxReportMarkdown)
	if err := ensureRenderable(args.Markdown); err != nil {
		return "", err
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
	// Page numbers bottom-center — multi-page incident reports used to ship
	// with no way to reference "page 3" (audit 2026-11-12).
	pdf.SetFooterFunc(func() {
		pdf.SetY(-12)
		pdf.SetFont("Arial", "", 8)
		pdf.SetTextColor(148, 163, 184)
		pdf.CellFormat(0, 8, fmt.Sprintf("Page %d", pdf.PageNo()), "", 0, "C", false, 0, "")
	})
	pdf.AddPage()

	// Map the remaining UTF-8 (— • …) onto cp1252 — the core Arial is Latin-1
	// and raw UTF-8 printed as "â€”".
	tr := pdf.UnicodeTranslatorFromDescriptor("")

	// Title block
	pdf.SetFont("Arial", "B", 18)
	pdf.SetTextColor(30, 41, 59)
	pdf.MultiCell(0, 9, tr(sanitizePDF(title)), "", "L", false)
	pdf.SetFont("Arial", "", 8)
	pdf.SetTextColor(100, 116, 139)
	pdf.Cell(0, 5, tr(time.Now().In(schedule.WIB).Format("2 January 2006, 15:04"))+" — Technical Risk Dashboard")
	pdf.Ln(9)

	pdf.SetTextColor(30, 41, 59)

	lines := strings.Split(markdown, "\n")
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		switch {
		case trimmed == "":
			pdf.Ln(2)
		case isMarkdownTableRow(trimmed):
			renderTableRow(pdf, tr, trimmed)
		case strings.HasPrefix(trimmed, "### "):
			pdf.SetFont("Arial", "B", 11)
			pdf.MultiCell(0, 5.5, tr(sanitizePDF(trimmed[4:])), "", "L", false)
			pdf.Ln(1)
		case strings.HasPrefix(trimmed, "## "):
			pdf.Ln(2)
			pdf.SetFont("Arial", "B", 13)
			pdf.MultiCell(0, 6.5, tr(sanitizePDF(trimmed[3:])), "", "L", false)
		case strings.HasPrefix(trimmed, "# "):
			pdf.Ln(2)
			pdf.SetFont("Arial", "B", 15)
			pdf.MultiCell(0, 7.5, tr(sanitizePDF(trimmed[2:])), "", "L", false)
		case strings.HasPrefix(trimmed, "- ") || strings.HasPrefix(trimmed, "* "):
			pdf.SetFont("Arial", "", 10)
			x := pdf.GetX()
			pdf.Cell(4, 5, tr("•"))
			pdf.SetX(x + 4)
			writeBoldAware(pdf, tr, trimmed[2:])
		default:
			pdf.SetFont("Arial", "", 10)
			writeBoldAware(pdf, tr, trimmed)
		}
	}
	return pdf.OutputFileAndClose(path)
}

// writeBoldAware writes text with **bold** segments emphasized IN LINE —
// each MultiCell segment used to force a line break, so one bold phrase
// turned its paragraph into three lines.
func writeBoldAware(pdf *fpdf.Fpdf, tr func(string) string, text string) {
	parts := boldSplit(sanitizePDF(text))
	pdf.SetFont("Arial", "", 10)
	for i, part := range parts {
		if i%2 == 1 {
			pdf.SetFont("Arial", "B", 10)
		} else {
			pdf.SetFont("Arial", "", 10)
		}
		pdf.Write(5, part)
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

// cjkRe matches script ranges the core (Latin-1) PDF fonts cannot render at
// all. Dropping them silently used to turn a whole CJK paragraph into an
// EMPTY line in the shipped PDF with no warning (audit 2026-11-12) — now the
// generator refuses and says why instead of producing a hollow document.
var cjkRe = regexp.MustCompile(`[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}]`)

// ensureRenderable reports whether the report text survives sanitization
// with meaning intact: any CJK content means the core fonts cannot carry it.
func ensureRenderable(markdown string) error {
	if cjkRe.MatchString(markdown) {
		return fmt.Errorf("report contains Chinese/Japanese/Korean text, which this PDF generator's core fonts cannot render — send the summary as a chat message instead")
	}
	return nil
}

// isMarkdownTableRow: | cell | cell | (≥2 pipes). Separator rows
// (|---|---|) render as a rule line, header rows render bold.
func isMarkdownTableRow(ln string) bool {
	return strings.HasPrefix(ln, "|") && strings.Count(ln, "|") >= 2
}

func renderTableRow(pdf *fpdf.Fpdf, tr func(string) string, ln string) {
	ln = strings.TrimSpace(strings.Trim(ln, "|"))
	cells := strings.Split(ln, "|")
	// separator row |---|---| → thin rule
	sep := true
	for _, c := range cells {
		c = strings.TrimSpace(c)
		if c != "" && strings.Trim(c, ":- ") != "" {
			sep = false
			break
		}
	}
	if sep {
		pdf.SetFont("Arial", "", 10)
		pdf.SetDrawColor(203, 213, 225)
		y := pdf.GetY()
		pdf.Line(18, y, 192, y)
		pdf.Ln(3)
		return
	}
	width := 174.0 / float64(len(cells))
	pdf.SetFont("Arial", "", 9)
	for _, c := range cells {
		c = strings.TrimSpace(c)
		if strings.HasPrefix(c, "**") && strings.HasSuffix(c, "**") && len(c) > 4 {
			c = c[2 : len(c)-2]
			pdf.SetFont("Arial", "B", 9)
		} else {
			pdf.SetFont("Arial", "", 9)
		}
		pdf.MultiCell(width, 4.5, tr(sanitizePDF(c)), "", "L", false)
		pdf.SetX(pdf.GetX() + width)
		if pdf.GetX() >= 192 {
			pdf.SetX(18)
		}
	}
	pdf.Ln(4.5)
	pdf.SetX(18)
}
