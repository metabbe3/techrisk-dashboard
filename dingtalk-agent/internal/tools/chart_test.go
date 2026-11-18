// chart_test.go — generate_chart: render a one-page chart PDF from agent-passed
// series and return the generated-file marker. TDD: tests written first.
package tools

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// newChartRegistry builds a registry with a temp DataDir so exports land in
// the test's scratch space; returns the temp dir so tests can read the export.
func newChartRegistry(t *testing.T) (*Registry, string) {
	t.Helper()
	tmp := t.TempDir()
	cfg := &config.Config{DashboardAPIBase: "http://unused.invalid", DashboardAPIToken: "t", DataDir: tmp}
	reg := NewRegistry(cfg)
	if !reg.Has("generate_chart") {
		t.Fatal("generate_chart not registered")
	}
	return reg, tmp
}

// exportedPDF finds the single PDF under <tmp>/exports and returns its bytes.
func exportedPDF(t *testing.T, tmp string) []byte {
	t.Helper()
	matches, err := filepath.Glob(filepath.Join(tmp, "exports", "*.pdf"))
	if err != nil || len(matches) != 1 {
		t.Fatalf("expected exactly one PDF export, found %d (%v)", len(matches), err)
	}
	raw, err := os.ReadFile(matches[0])
	if err != nil {
		t.Fatalf("export file unreadable: %v", err)
	}
	return raw
}

// runChart executes generate_chart and returns (output, export bytes).
func runChart(t *testing.T, reg *Registry, tmp, argsJSON string) (string, []byte) {
	t.Helper()
	out := execChart(t, reg, argsJSON)
	return out, exportedPDF(t, tmp)
}

// execChart executes generate_chart and returns the output content only —
// for rejection cases where no file is expected to exist.
func execChart(t *testing.T, reg *Registry, argsJSON string) string {
	t.Helper()
	out, err := reg.Execute(context.Background(), "generate_chart", argsJSON)
	if err != nil {
		t.Fatalf("generate_chart failed: %v", err)
	}
	return out
}

func TestGenerateChartBarRendersPDFWithMarker(t *testing.T) {
	reg, tmp := newChartRegistry(t)
	out, raw := runChart(t, reg, tmp, `{"kind":"bar","title":"MTTR by month","labels":"Jan;Feb;Mar","values":"41;33;27","value_suffix":" min"}`)
	if !strings.Contains(out, "PDF created") || !strings.Contains(out, "[generated-file: exports/") {
		t.Fatalf("expected creation message + marker, got %.160s", out)
	}
	if !strings.HasPrefix(string(raw[:4]), "%PDF") {
		t.Errorf("not a PDF: % x", raw[:4])
	}
}

func TestGenerateChartLineWithNegativeValues(t *testing.T) {
	// MTTR can be NEGATIVE (elapsed calendar days, no +1) — the chart must
	// draw a zero baseline and still render.
	reg, tmp := newChartRegistry(t)
	out, raw := runChart(t, reg, tmp, `{"kind":"line","title":"MTTR trend","labels":"Jan;Feb;Mar;Apr","values":"-5;-2;8;12"}`)
	if !strings.Contains(out, "[generated-file: exports/") {
		t.Fatalf("expected marker, got %.160s", out)
	}
	if string(raw[:4]) != "%PDF" {
		t.Errorf("not a PDF: % x", raw[:4])
	}
}

func TestGenerateChartRejectsBadArgs(t *testing.T) {
	cases := []struct {
		name string
		args string
		want string
	}{
		{"empty values", `{"kind":"","title":"t","labels":"a","values":""}`, "kind must be"},
		{"count mismatch", `{"kind":"bar","title":"t","labels":"a;b","values":"1"}`, "same number"},
		{"non-numeric", `{"kind":"bar","title":"t","labels":"a","values":"x"}`, "number"},
		{"invalid kind", `{"kind":"pie","title":"t","labels":"a","values":"1"}`, "kind must be"},
		{"too many points", `{"kind":"bar","title":"t","labels":"` + manyLabels(41) + `","values":"` + manyValues(41) + `"}`, "max 40"},
	}
	reg, _ := newChartRegistry(t)
	for _, tc := range cases {
		out := execChart(t, reg, tc.args)
		if !strings.Contains(out, "Error executing generate_chart") || !strings.Contains(out, tc.want) {
			t.Errorf("%s: expected rejection mentioning %q, got %.140s", tc.name, tc.want, out)
		}
	}
}

func manyLabels(n int) string {
	parts := make([]string, n)
	for i := range parts {
		parts[i] = "L"
	}
	return strings.Join(parts, ";")
}

func manyValues(n int) string {
	parts := make([]string, n)
	for i := range parts {
		parts[i] = "1"
	}
	return strings.Join(parts, ";")
}

// TestGenerateChartCJKRefused pins the CJK gate: the core PDF fonts cannot
// render CJK — the tool must refuse like generate_pdf_report does instead of
// shipping hollow output.
func TestGenerateChartCJKRefused(t *testing.T) {
	reg, _ := newChartRegistry(t)
	out := execChart(t, reg, `{"kind":"bar","title":"趋势","labels":"a","values":"1"}`)
	if !strings.Contains(out, "Error executing generate_chart") {
		t.Errorf("CJK title must be refused, got %.140s", out)
	}
}
