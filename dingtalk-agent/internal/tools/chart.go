// chart.go — generate_chart: render a one-page bar/line chart PDF from a
// series the model passes (numbers it got from get_dashboard_stats — never
// fabricated). A deliverable capability scoped by the guardrails to dashboard
// content; delivered through the same generated-file marker as
// generate_pdf_report.
package tools

import (
	"context"
	"fmt"
	"math"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/go-pdf/fpdf"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
	"techrisk-dashboard/dingtalk-agent/internal/util"
)

const maxChartPoints = 40

func generateChartDef() llm.ToolDefinition {
	// Schemas must stay FLAT (string/number/integer/boolean properties with
	// type+description only) — arrays 400 the whole request on strict
	// upstreams (2026-11-10 outage), so series ride semicolon-joined strings,
	// the same trick generate_deck uses for slides.
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "generate_chart",
			Description: "Render a bar or line chart to a one-page PDF and send it to the user. Use for 'show me a chart/graph of…' requests on a series you already fetched (e.g. get_dashboard_stats). Numbers must come from tool results — never fabricate data.",
			Parameters: schema(
				map[string]any{
					"kind":         map[string]any{"type": "string", "description": "Chart type: 'bar' or 'line'"},
					"title":        map[string]any{"type": "string", "description": "Chart title"},
					"labels":       map[string]any{"type": "string", "description": "X-axis labels, semicolon-separated: 'Jan;Feb;Mar'"},
					"values":       map[string]any{"type": "string", "description": "Series values, semicolon-separated numbers (negatives allowed): '41;33;27' or '-5;-2;8'"},
					"value_suffix": map[string]any{"type": "string", "description": "Optional unit printed after values, e.g. 'min' or 'incidents'"},
				},
				[]string{"kind", "title", "labels", "values"},
			),
		},
	}
}

func (r *Registry) registerChartTool(dir string) {
	r.Register(generateChartDef(), typed(func(ctx context.Context, args struct {
		Kind        string `json:"kind"`
		Title       string `json:"title"`
		Labels      string `json:"labels"`
		Values      string `json:"values"`
		ValueSuffix string `json:"value_suffix"`
	}) (string, error) {
		return handleGenerateChart(dir, args.Kind, args.Title, args.Labels, args.Values, args.ValueSuffix)
	}))
}

func handleGenerateChart(dir, kind, title, labelsCSV, valuesCSV, suffix string) (string, error) {
	kind = strings.ToLower(strings.TrimSpace(kind))
	if kind != "bar" && kind != "line" {
		return "", fmt.Errorf("kind must be 'bar' or 'line'")
	}
	labels := splitChartStrings(labelsCSV)
	if len(labels) == 0 {
		return "", fmt.Errorf("labels is empty — pass semicolon-separated labels")
	}
	values, err := splitChartNumbers(valuesCSV)
	if err != nil {
		return "", err
	}
	if len(labels) != len(values) {
		return "", fmt.Errorf("labels and values must have the same number of items (%d labels vs %d values)", len(labels), len(values))
	}
	title = orDefaultStr(title, "Chart")
	// Same CJK gate as generate_pdf_report: core fonts cannot render CJK and
	// silently dropping it hollows out the chart.
	if err := ensureRenderable(title + " " + labelsCSV + " " + suffix); err != nil {
		return "", err
	}

	path, err := exportPath(dir, title, "pdf")
	if err != nil {
		return "", err
	}
	if err := renderChartPDF(path, kind, title, labels, values, suffix); err != nil {
		return "", err
	}
	rel, _ := filepath.Rel(dir, path)
	return fmt.Sprintf("Chart PDF created: %s — tell the user the chart was generated and sent to their DM. Include this marker verbatim in your reply: "+GeneratedFileMarker, rel, rel), nil
}

func splitChartStrings(csv string) []string {
	var out []string
	for _, p := range strings.Split(csv, ";") {
		p = strings.TrimSpace(p)
		if p != "" {
			out = append(out, p)
		}
	}
	return out
}

func splitChartNumbers(csv string) ([]float64, error) {
	fields := strings.Split(csv, ";")
	var out []float64
	for _, p := range fields {
		p = strings.TrimSpace(p)
		if p == "" {
			continue
		}
		v, err := strconv.ParseFloat(p, 64)
		if err != nil || math.IsNaN(v) || math.IsInf(v, 0) {
			return nil, fmt.Errorf("values must be semicolon-separated numbers (got %q)", p)
		}
		out = append(out, v)
	}
	if len(out) == 0 {
		return nil, fmt.Errorf("values is empty — pass semicolon-separated numbers")
	}
	if len(out) > maxChartPoints {
		return nil, fmt.Errorf("too many data points (max 40) — chart a coarser series")
	}
	return out, nil
}

// formatChartVal renders an axis/point label: 2 decimals with trailing zeros
// trimmed — full float64 digits printed 16-char axis labels at small spans
// (review 2026-11-18); coarse spans (≥10) keep the integer look; magnitudes
// beyond int64 go scientific so the int conversion can't overflow.
func formatChartVal(v, span float64) string {
	if math.Abs(v) >= 1e15 {
		return strconv.FormatFloat(v, 'e', 2, 64)
	}
	if span >= 10 {
		return strconv.Itoa(int(math.Round(v)))
	}
	s := strconv.FormatFloat(v, 'f', 2, 64)
	if i := strings.IndexByte(s, '.'); i >= 0 {
		if frac := strings.TrimRight(s[i+1:], "0"); frac != "" {
			return s[:i] + "." + frac
		}
		return s[:i]
	}
	return s
}

// renderChartPDF draws one chart per A4 page: title block, y grid with value
// labels, a zero baseline (MTTR goes NEGATIVE on the elapsed-days convention),
// bars or a line, values printed at each point, x labels.
// ponytail: single-series only — a second series (legend, colors) when a real
// ask needs it.
func renderChartPDF(path, kind, title string, labels []string, values []float64, suffix string) error {
	suffix = sanitizePDF(suffix) // unit rides every label — strip unrenderables once here
	pdf := fpdf.New("P", "mm", "A4", "")
	pdf.SetMargins(18, 16, 18)
	pdf.AddPage()
	tr := pdf.UnicodeTranslatorFromDescriptor("")

	// Title block — same style as the report renderer.
	pdf.SetFont("Arial", "B", 15)
	pdf.SetTextColor(30, 41, 59)
	pdf.MultiCell(0, 8, tr(sanitizePDF(title)), "", "L", false)
	pdf.SetFont("Arial", "", 8)
	pdf.SetTextColor(100, 116, 139)
	pdf.Cell(0, 5, tr(timeNowWIB()+" — Technical Risk Dashboard"))
	pdf.Ln(10)

	// Plot geometry.
	const (
		left, right, topGap, bottom = 24.0, 18.0, 6.0, 24.0
	)
	pw, ph := pdf.GetPageSize()
	plotL, plotR := left, pw-right
	plotT := pdf.GetY() + topGap
	plotB := ph - bottom

	minv, maxv := math.Inf(1), math.Inf(-1)
	for _, v := range values {
		minv = math.Min(minv, v)
		maxv = math.Max(maxv, v)
	}
	minv = math.Min(minv, 0)
	maxv = math.Max(maxv, 0)
	if minv == maxv {
		minv, maxv = 0, 1 // flat series still draws
	}
	span := maxv - minv

	y := func(v float64) float64 { return plotB - (v-minv)/span*(plotB-plotT) }
	fmtVal := func(v float64) string { return formatChartVal(v, span) }
	suffixOf := func(v float64) string {
		s := fmtVal(v)
		if suffix != "" {
			s += suffix
		}
		return s
	}

	// Y gridlines + labels (top, three-quarter, half, quarter, baseline).
	// Drawn with pdf.Text AT the gridline — Cell renders at the cursor Y,
	// which stacked every label on the top-left blob (caught by eyeballing
	// the demo render, 2026-11-18).
	pdf.SetFont("Arial", "", 7)
	pdf.SetTextColor(100, 116, 139)
	pdf.SetDrawColor(226, 232, 240)
	for i := 0; i <= 4; i++ {
		v := minv + span*float64(i)/4
		yy := y(v)
		pdf.Text(plotL-21, yy+1, tr(fmtVal(v)+suffix))
		pdf.Line(plotL, yy, plotR, yy)
	}

	// Zero baseline (axis) — darker.
	pdf.SetDrawColor(100, 116, 139)
	pdf.Line(plotL, y(0), plotR, y(0))

	n := float64(len(values))
	slot := (plotR - plotL) / n
	cx := func(i int) float64 { return plotL + slot*(float64(i)+0.5) }

	pdf.SetTextColor(30, 41, 59)
	switch kind {
	case "bar":
		pdf.SetFillColor(79, 70, 229)
		barW := slot * 0.6
		for i, v := range values {
			x0 := cx(i) - barW/2
			y0, y1 := y(math.Max(v, 0)), y(math.Min(v, 0))
			pdf.Rect(x0, y0, barW, y1-y0, "F")
		}
	case "line":
		pdf.SetDrawColor(79, 70, 229)
		pdf.SetLineWidth(0.8)
		for i := range values {
			if i > 0 {
				pdf.Line(cx(i-1), y(values[i-1]), cx(i), y(values[i]))
			}
		}
		pdf.SetFillColor(79, 70, 229)
		for i := range values {
			pdf.Circle(cx(i), y(values[i]), 1.3, "F")
		}
	}

	// Value at each point: above positive points, below negative ones —
	// negative labels tuck in tight (y+2.8) so the lowest one clears the
	// x-label line at plotB+6.
	pdf.SetFont("Arial", "", 7)
	for i, v := range values {
		yy := y(v) - 1.5
		if v < 0 {
			yy = y(v) + 2.8
		}
		pdf.Text(cx(i)-float64(len(fmtVal(v)+suffix))*0.55, yy, tr(suffixOf(v)))
	}

	// X labels (skip labels when they would collide).
	pdf.SetFont("Arial", "", 7)
	pdf.SetTextColor(71, 85, 105)
	every := 1
	for slot*float64(every) < 9 && every < len(labels) {
		every++
	}
	for i, l := range labels {
		if i%every != 0 {
			continue
		}
		// Long labels overlap neighbours and run off the right margin —
		// truncate to roughly the slot width (Arial 7pt ≈ 1.5mm/char).
		l = util.Cut(l, max(4, int(slot*float64(every)/1.5)))
		w := slot*float64(every) - 1
		if w < 8 {
			w = 8
		}
		pdf.Text(cx(i)-w/2, plotB+6, tr(sanitizePDF(l)))
	}
	return pdf.OutputFileAndClose(path)
}

func timeNowWIB() string {
	return time.Now().In(schedule.WIB).Format("2 January 2006, 15:04")
}
