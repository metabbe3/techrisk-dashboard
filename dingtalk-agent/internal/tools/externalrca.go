// externalrca.go — generate_external_rca: fill the owner's partner-facing
// incident-report Word template with content the model composed from the
// incident record. The template carries the DANA format (logo, confidential
// banner, styles, chronology table, footer) — this file only injects values,
// so format changes are template edits (templates/build_external_rca_template.py
// rebuilds it from a fresh sample).
package tools

import (
	"archive/zip"
	"bytes"
	"context"
	_ "embed"
	"encoding/json"
	"encoding/xml"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

//go:embed templates/external-rca.docx
var externalRCATemplate []byte

const (
	maxRCAActions = 10
	maxRCARows    = 30
)

// rcaChronRe: one chronology event per tool-arg line —
// "YYYY-MM-DD HH:MM | event text".
var rcaChronRe = regexp.MustCompile(`^\s*(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})\s*\|\s*(\S.*)$`)

var (
	rcaParaRe = regexp.MustCompile(`(?s)<w:p(?:\s[^>]*)?>.*?</w:p>`)
	rcaRowRe  = regexp.MustCompile(`(?s)<w:tr(?:\s[^>]*)?>.*?</w:tr>`)
)

func externalRCADef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "generate_external_rca",
			Description: "Generate the partner-facing external incident report (DANA Word template) as a .docx file " +
				"and send it to the user. Use ONLY after the user confirmed the draft text in chat (summary, problem, " +
				"root cause, corrective actions, chronology). incident_no must be a real dashboard incident number — " +
				"the report date comes from the record (incident date + 1 day), never invented. " +
				"actions: one corrective action per line (max 10). chronology: one event per line, " +
				"'YYYY-MM-DD HH:MM | event' in WIB (max 30). Wording must be neutral and non-technical — " +
				"see the External RCA section of the system prompt.",
			Parameters: schema(
				map[string]any{
					"incident_no": map[string]any{"type": "string", "description": "Incident number, e.g. 20250115_IN_1234"},
					"summary":     map[string]any{"type": "string", "description": "Executive Summary paragraph (3-4 sentences: found -> investigated -> mitigation -> recovery; neutral, non-technical)"},
					"problem":     map[string]any{"type": "string", "description": "Problem Statement paragraph — ONE cause->effect sentence naming the mechanism class (detailed, never generic)"},
					"root_cause":  map[string]any{"type": "string", "description": "Root cause paragraph — ONE sentence naming the concrete mechanism, neutral non-technical; when the record lacks the cause, infer the most plausible one from the chronology with hedged wording; no blame, no internal system/team names"},
					"actions":     map[string]any{"type": "string", "description": "Corrective actions, one per line (max 10)"},
					"chronology":  map[string]any{"type": "string", "description": "Time chronology events, one per line: 'YYYY-MM-DD HH:MM | event' (max 30)"},
				},
				[]string{"incident_no", "summary", "problem", "root_cause", "actions", "chronology"},
			),
		},
	}
}

type externalRCAArgs struct {
	IncidentNo string `json:"incident_no"`
	Summary    string `json:"summary"`
	Problem    string `json:"problem"`
	RootCause  string `json:"root_cause"`
	Actions    string `json:"actions"`
	Chronology string `json:"chronology"`
}

func (r *Registry) handleExternalRCA(ctx context.Context, dir string, args externalRCAArgs) (string, error) {
	dash := r.Dashboard()
	if dash == nil {
		return "", fmt.Errorf("dashboard client unavailable — cannot resolve the incident's date")
	}
	raw, err := dash.GetIncidentByNo(ctx, args.IncidentNo)
	if err != nil {
		return "", fmt.Errorf("cannot fetch incident %s: %w", args.IncidentNo, err)
	}
	// The report and header dates come from the RECORD, never the model —
	// an invented date in a partner document is a credibility incident of
	// its own (owner: "date on top = 1 day after incident").
	var rec struct {
		IncidentDate string `json:"incident_date"`
	}
	if err := json.Unmarshal(raw, &rec); err != nil || rec.IncidentDate == "" {
		return "", fmt.Errorf("incident %s has no incident_date in the dashboard record", args.IncidentNo)
	}
	// The detail API serializes incident_date as ISO-8601 (Carbon toJSON,
	// e.g. "2026-11-06T00:00:00.000000Z"); plain "2006-01-02" appears in
	// fixtures and hand-built rows. Accept both — Format() keeps the parsed
	// zone, so the calendar date stays exactly as recorded. (The strict
	// Y-m-d parse errored on every REAL record; a model that sees the error
	// improvises with generate_pdf_report — the "why is this a PDF" symptom.)
	incidentDate, err := parseRCADate(rec.IncidentDate)
	if err != nil {
		return "", fmt.Errorf("incident %s: unreadable incident_date %q", args.IncidentNo, rec.IncidentDate)
	}

	summary, problem, rootCause := strings.TrimSpace(args.Summary), strings.TrimSpace(args.Problem), strings.TrimSpace(args.RootCause)
	if summary == "" || problem == "" || rootCause == "" {
		return "", fmt.Errorf("summary, problem and root_cause are all required — compose them from the incident record and get the user's confirmation first")
	}
	actions, err := parseRCAActions(args.Actions)
	if err != nil {
		return "", err
	}
	rows, err := parseRCAChronology(args.Chronology)
	if err != nil {
		return "", err
	}

	doc, err := fillExternalRCADoc(
		incidentDate.AddDate(0, 0, 1).Format("02 Jan 2006"), // header date = incident + 1 day
		incidentDate.Format("02 Jan 2006"),
		summary, problem, rootCause, actions, rows,
	)
	if err != nil {
		return "", err
	}

	path, err := exportPath(dir, "External RCA "+strings.ReplaceAll(rec.IncidentDate, "-", ""), "docx")
	if err != nil {
		return "", err
	}
	if err := os.WriteFile(path, doc, 0o644); err != nil {
		return "", fmt.Errorf("cannot write the report: %w", err)
	}
	rel, _ := filepath.Rel(dir, path)
	return fmt.Sprintf("External RCA created: %s — tell the user it was generated and sent. Include this marker verbatim in your reply: "+GeneratedFileMarker, rel, rel), nil
}

func parseRCAActions(raw string) ([]string, error) {
	var out []string
	for _, line := range strings.Split(raw, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		// the model often numbers them ("1. Implemented ...") — strip the prefix
		line = strings.TrimLeft(line, "0123456789.-) ")
		if line == "" {
			continue
		}
		out = append(out, line)
	}
	if len(out) == 0 {
		return nil, fmt.Errorf("actions is empty — compose at least one corrective action from the record")
	}
	if len(out) > maxRCAActions {
		return nil, fmt.Errorf("too many actions (%d) — cap %d", len(out), maxRCAActions)
	}
	return out, nil
}

type rcaRow struct{ date, time, event string }

// parseRCADate parses the record's incident_date in either serialization the
// dashboard emits: plain "2006-01-02" or Carbon's ISO-8601 toJSON (RFC3339
// with fractional seconds). Format() keeps the parsed zone, so the calendar
// date stays exactly as recorded.
func parseRCADate(s string) (time.Time, error) {
	for _, layout := range []string{"2006-01-02", time.RFC3339Nano} {
		if t, err := time.Parse(layout, s); err == nil {
			return t, nil
		}
	}
	return time.Time{}, fmt.Errorf("unparseable incident_date %q", s)
}

func parseRCAChronology(raw string) ([]rcaRow, error) {
	var out []rcaRow
	for _, line := range strings.Split(raw, "\n") {
		if strings.TrimSpace(line) == "" {
			continue
		}
		m := rcaChronRe.FindStringSubmatch(line)
		if m == nil {
			return nil, fmt.Errorf("chronology line %q does not match 'YYYY-MM-DD HH:MM | event' — fix it and retry", strings.TrimSpace(line))
		}
		d, err := time.Parse("2006-01-02", m[1])
		if err != nil {
			return nil, fmt.Errorf("chronology line %q: %s is not a real date", strings.TrimSpace(line), m[1])
		}
		out = append(out, rcaRow{date: d.Format("02 Jan 2006"), time: m[2], event: strings.TrimSpace(m[3])})
	}
	if len(out) == 0 {
		return nil, fmt.Errorf("chronology is empty — list the events from the record (discovery, investigation, fix, recovery)")
	}
	if len(out) > maxRCARows {
		return nil, fmt.Errorf("too many chronology rows (%d) — cap %d", len(out), maxRCARows)
	}
	return out, nil
}

// fillExternalRCADoc clones the template's single {{ACTION}} paragraph and
// chronology row once per entry — Word numbers list paragraphs 1..N via the
// shared numId — and swaps every other placeholder once. Every value is
// XML-escaped: a raw '&' produced invalid XML (Word: "unreadable content").
func fillExternalRCADoc(reportDate, incidentDate, summary, problem, rootCause string, actions []string, rows []rcaRow) ([]byte, error) {
	zr, err := zip.NewReader(bytes.NewReader(externalRCATemplate), int64(len(externalRCATemplate)))
	if err != nil {
		return nil, fmt.Errorf("embedded template unreadable: %w", err)
	}
	var doc string
	type part struct {
		name string
		body []byte
	}
	var parts []part
	for _, f := range zr.File {
		body, err := readZipEntry(f)
		if err != nil {
			return nil, err
		}
		if f.Name == "word/document.xml" {
			doc = string(body)
		}
		parts = append(parts, part{f.Name, body})
	}
	if doc == "" {
		return nil, fmt.Errorf("template has no word/document.xml")
	}

	esc := func(s string) string {
		var b bytes.Buffer
		if err := xml.EscapeText(&b, []byte(s)); err != nil {
			return ""
		}
		return b.String()
	}

	d := repeatRCAPrototype(doc, "{{ACTION}}", len(actions), func(para string, i int) string {
		return strings.Replace(para, "{{ACTION}}", esc(actions[i]), 1)
	})
	d = repeatRCAPrototype(d, "{{ROW_DATE}}", len(rows), func(row string, i int) string {
		return strings.NewReplacer(
			"{{ROW_DATE}}", esc(rows[i].date),
			"{{ROW_TIME}}", esc(rows[i].time),
			"{{ROW_EVENT}}", esc(rows[i].event)).Replace(row)
	})
	for ph, val := range map[string]string{
		"{{REPORT_DATE}}":   esc(reportDate),
		"{{INCIDENT_DATE}}": esc(incidentDate),
		"{{SUMMARY}}":       esc(summary),
		"{{PROBLEM}}":       esc(problem),
		"{{ROOT_CAUSE}}":    esc(rootCause),
	} {
		if !strings.Contains(d, ph) {
			return nil, fmt.Errorf("template placeholder %s missing — rebuild the template", ph)
		}
		d = strings.Replace(d, ph, val, 1)
	}
	if strings.Contains(d, "{{") {
		return nil, fmt.Errorf("template still contains a placeholder after filling — rebuild the template")
	}

	var out bytes.Buffer
	zw := zip.NewWriter(&out)
	for _, p := range parts {
		body := p.body
		if p.name == "word/document.xml" {
			body = []byte(d)
		}
		w, err := zw.Create(p.name)
		if err != nil {
			return nil, err
		}
		if _, err := w.Write(body); err != nil {
			return nil, err
		}
	}
	if err := zw.Close(); err != nil {
		return nil, err
	}
	return out.Bytes(), nil
}

// repeatRCAPrototype finds the enclosing element (a <w:p> for actions, a
// <w:tr> for chronology rows) of the placeholder, stamps out n copies with
// entry i's values swapped in, and replaces the single prototype occurrence.
func repeatRCAPrototype(doc, ph string, n int, fill func(elem string, i int) string) string {
	idx := strings.Index(doc, ph)
	if idx < 0 {
		return doc
	}
	var elem string
	if n > 0 {
		elem = rcaEnclosing(doc, idx)
	}
	var sb strings.Builder
	for i := 0; i < n; i++ {
		sb.WriteString(fill(elem, i))
	}
	return strings.Replace(doc, elem, sb.String(), 1)
}

// rcaEnclosing returns the innermost paragraph or table row containing idx.
func rcaEnclosing(doc string, idx int) string {
	best := ""
	for _, m := range rcaParaRe.FindAllStringIndex(doc, -1) {
		if m[0] <= idx && idx < m[1] {
			best = doc[m[0]:m[1]]
		}
	}
	for _, m := range rcaRowRe.FindAllStringIndex(doc, -1) {
		if m[0] <= idx && idx < m[1] {
			return doc[m[0]:m[1]] // a row encloses its paragraphs
		}
	}
	return best
}

func readZipEntry(f *zip.File) ([]byte, error) {
	rc, err := f.Open()
	if err != nil {
		return nil, err
	}
	defer rc.Close()
	var buf bytes.Buffer
	if _, err := buf.ReadFrom(rc); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
