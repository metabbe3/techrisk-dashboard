package tools

import (
	"archive/zip"
	"bytes"
	"context"
	"encoding/json"
	"encoding/xml"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// rcaSample is the approved 05 Nov 2026 report content — the fixture doubles
// as the style example taught in ExternalRCAPrompt.
var rcaSample = externalRCAArgs{
	IncidentNo: "20261105_IN_0001",
	Summary:    "On 05 Nov 2026, DANA Team found an anomaly in QRIS transactions from Tokopedia. DANA Team immediately investigated together with Tokopedia Team and found an issue in our system's traffic handling. DANA Team implemented a fix, and transactions soon recovered.",
	Problem:    "Abnormal traffic handling in our services caused part of the QRIS generation requests from Tokopedia to fail, and Tokopedia QRIS transactions dropped.",
	RootCause:  "Abnormal traffic handling in our services caused some Tokopedia QRIS requests to fail.",
	Actions: "Implemented a fix on our traffic handling so Tokopedia transactions can be processed normally.\n" +
		"Coordinated with Tokopedia Team to resume the transaction flow and validate the fix.\n" +
		"Monitored the QRIS transaction trend to ensure normal transaction flow.\n" +
		"Review traffic handling for partner transactions to prevent similar issues in the future.",
	Chronology: "2026-11-05 12:36 | DANA Team found a drop in Tokopedia QRIS transactions.\n" +
		"2026-11-05 12:44 | Tokopedia Team reported an issue when generating QRIS, and DANA Team started to troubleshoot.\n" +
		"2026-11-05 13:14 | DANA Team continued the investigation together with Tokopedia Team.\n" +
		"2026-11-05 13:52 | DANA Team escalated the issue and gathered related internal teams to speed up the investigation.\n" +
		"2026-11-05 13:54 | DANA Team found abnormal traffic handling affecting Tokopedia transactions.\n" +
		"2026-11-05 14:00 | DANA Team started to implement the fix.\n" +
		"2026-11-05 14:26 | Fix completed, and DANA Team informed Tokopedia Team to resume transactions.\n" +
		"2026-11-05 14:56 | Transactions gradually recovered, and DANA Team continued monitoring.\n" +
		"2026-11-05 15:21 | Transaction flow observed stable, and DANA Team continued to monitor the QRIS trend.",
}

// rcaTestRegistry fakes the dashboard (incident_date from the record) and
// points DataDir at a temp dir so the handler's written .docx is readable.
func rcaTestRegistry(t *testing.T, incidentDate string) (*Registry, string) {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.Contains(r.URL.Path, "/incidents-by-no/") {
			t.Errorf("unexpected dashboard call: %s", r.URL.Path)
		}
		envelope(w, map[string]any{
			"incident_no":   "20261105_IN_0001",
			"incident_date": incidentDate,
		}, nil)
	}))
	t.Cleanup(server.Close)
	dir := t.TempDir()
	reg := NewRegistry(&config.Config{DashboardAPIBase: server.URL, DashboardAPIToken: "t", DataDir: dir})
	return reg, dir
}

func rcaJSON(t *testing.T, a externalRCAArgs) string {
	t.Helper()
	b, err := json.Marshal(a)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

// generatedRCADoc runs the tool end-to-end and returns the written .docx bytes.
func generatedRCADoc(t *testing.T, reg *Registry, dir string, args externalRCAArgs) []byte {
	t.Helper()
	out, err := reg.Execute(context.Background(), "generate_external_rca", rcaJSON(t, args))
	if err != nil {
		t.Fatalf("execute: %v", err)
	}
	m := regexp.MustCompile(`\[generated-file: ([^\]]+)\]`).FindStringSubmatch(out)
	if m == nil {
		t.Fatalf("no generated-file marker in: %s", out)
	}
	body, err := os.ReadFile(filepath.Join(dir, m[1]))
	if err != nil {
		t.Fatalf("generated file missing at %s: %v", m[1], err)
	}
	return body
}

func readRCAZip(t *testing.T, docx []byte) map[string][]byte {
	t.Helper()
	zr, err := zip.NewReader(bytes.NewReader(docx), int64(len(docx)))
	if err != nil {
		t.Fatalf("output is not a valid zip: %v", err)
	}
	out := map[string][]byte{}
	for _, f := range zr.File {
		rc, err := f.Open()
		if err != nil {
			t.Fatalf("open %s: %v", f.Name, err)
		}
		body, _ := io.ReadAll(rc)
		rc.Close()
		out[f.Name] = body
	}
	return out
}

// rcaText returns document.xml after verifying it is well-formed XML.
func rcaText(t *testing.T, docx []byte) string {
	t.Helper()
	doc := readRCAZip(t, docx)["word/document.xml"]
	dec := xml.NewDecoder(bytes.NewReader(doc))
	for {
		_, err := dec.Token()
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatalf("document.xml is not well-formed: %v", err)
		}
	}
	return string(doc)
}

func TestExternalRCATemplatePlaceholdersSingle(t *testing.T) {
	doc := rcaText(t, externalRCATemplate)
	for _, ph := range []string{"{{REPORT_DATE}}", "{{INCIDENT_DATE}}", "{{SUMMARY}}", "{{PROBLEM}}",
		"{{ROOT_CAUSE}}", "{{ACTION}}", "{{ROW_DATE}}", "{{ROW_TIME}}", "{{ROW_EVENT}}"} {
		if n := strings.Count(doc, ph); n != 1 {
			t.Errorf("placeholder %s appears %d times in the template, want exactly 1", ph, n)
		}
	}
}

func TestExternalRCAHappyPathMatchesSampleFormat(t *testing.T) {
	reg, dir := rcaTestRegistry(t, "2026-11-05")
	doc := rcaText(t, generatedRCADoc(t, reg, dir, rcaSample))

	if !strings.Contains(doc, "06 Nov 2026") { // header date = incident + 1 day
		t.Error("report date 06 Nov 2026 missing — header date must be incident_date + 1 day")
	}
	if !strings.Contains(doc, "Incident Report for 05 Nov 2026 Incident") {
		t.Error("title 'Incident Report for 05 Nov 2026 Incident' missing")
	}
	// apostrophes are escaped (&#39;) by design — check apostrophe-free spans
	if !strings.Contains(doc, "DANA Team found an anomaly in QRIS transactions from Tokopedia") ||
		!strings.Contains(doc, rcaSample.Problem) {
		t.Error("summary/problem text missing")
	}
	if n := strings.Count(doc, "ListParagraph"); n != 4 {
		t.Errorf("action paragraphs (ListParagraph) = %d, want 4", n)
	}
	rows := regexp.MustCompile(`(?s)<w:tbl>.*?</w:tbl>`).FindAllString(doc, -1)
	if len(rows) != 2 {
		t.Fatalf("want 2 tables (header + chronology), got %d", len(rows))
	}
	if n := strings.Count(rows[1], "<w:tr"); n != 9 {
		t.Errorf("chronology rows = %d, want 9", n)
	}
	// the real reports render times as "12:36 GMT+7" — the template carries the
	// static suffix after {{ROW_TIME}} (2026-11-16 template patch)
	if !strings.Contains(rows[1], ">12:36 GMT+7<") {
		t.Error("chronology time cell does not render '12:36 GMT+7' — template lost the static GMT+7 suffix")
	}
	if strings.Contains(doc, "{{") {
		t.Error("placeholders left in the filled document")
	}
}

func TestExternalRCAXMLEscaping(t *testing.T) {
	a := rcaSample
	a.RootCause = `Traffic handling & <routing> in DANA's services "failed" for Tokopedia's requests.`
	reg, dir := rcaTestRegistry(t, "2026-11-05")
	doc := rcaText(t, generatedRCADoc(t, reg, dir, a)) // well-formedness check inside
	if !strings.Contains(doc, "Traffic handling &amp; &lt;routing&gt;") {
		t.Error("raw XML metacharacters were not escaped")
	}
}

func TestExternalRCADateRollover(t *testing.T) {
	reg, dir := rcaTestRegistry(t, "2026-12-31")
	doc := rcaText(t, generatedRCADoc(t, reg, dir, rcaSample))
	if !strings.Contains(doc, "01 Jan 2027") || !strings.Contains(doc, "Incident Report for 31 Dec 2026 Incident") {
		t.Error("2026-12-31 must render header 01 Jan 2027 and title 31 Dec 2026")
	}
}

func TestExternalRCAMalformedChronology(t *testing.T) {
	a := rcaSample
	a.Chronology = "2026-11-05 12:36 | found the drop\nthis line is wrong"
	reg, _ := rcaTestRegistry(t, "2026-11-05")
	// Registry.Execute returns handler errors AS the result string, not an error.
	out, err := reg.Execute(context.Background(), "generate_external_rca", rcaJSON(t, a))
	if err != nil {
		t.Fatalf("execute transport error: %v", err)
	}
	if !strings.Contains(out, "Error executing") || !strings.Contains(out, "this line is wrong") {
		t.Fatalf("want an error naming the malformed line, got: %s", out)
	}
}

func TestExternalRCAUnknownIncident(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNotFound)
		_ = json.NewEncoder(w).Encode(map[string]any{"code": 404, "status": "Error", "message": "no rows"})
	}))
	t.Cleanup(server.Close)
	reg := NewRegistry(&config.Config{DashboardAPIBase: server.URL, DashboardAPIToken: "t"})
	out, _ := reg.Execute(context.Background(), "generate_external_rca", rcaJSON(t, rcaSample))
	if !strings.Contains(out, "cannot fetch incident 20261105_IN_0001") {
		t.Fatalf("want an error naming the incident, got: %s", out)
	}
}

// The REAL dashboard serializes incident_date as ISO-8601 (Carbon toJSON:
// "2026-11-06T00:00:00.000000Z", verified via tinker). The strict Y-m-d parse
// errored on every real record and the model then improvised a generic PDF
// via generate_pdf_report — the "why is this a PDF" symptom.
func TestExternalRCAISODateAccepted(t *testing.T) {
	reg, dir := rcaTestRegistry(t, "2026-11-06T00:00:00.000000Z")
	doc := rcaText(t, generatedRCADoc(t, reg, dir, rcaSample))
	if !strings.Contains(doc, "07 Nov 2026") || !strings.Contains(doc, "Incident Report for 06 Nov 2026 Incident") {
		t.Error("ISO-8601 incident_date not parsed — header/title dates wrong")
	}
}

func TestExternalRCAParseRCADate(t *testing.T) {
	for good, want := range map[string]string{
		"2026-11-06":                  "06 Nov 2026",
		"2026-11-06T00:00:00.000000Z": "06 Nov 2026",
		"2026-12-31T17:00:00.000000Z": "31 Dec 2026",
	} {
		got, err := parseRCADate(good)
		if err != nil {
			t.Errorf("parseRCADate(%q): %v", good, err)
			continue
		}
		if f := got.Format("02 Jan 2006"); f != want {
			t.Errorf("parseRCADate(%q) = %s, want %s", good, f, want)
		}
	}
	if _, err := parseRCADate("garbage"); err == nil {
		t.Error("garbage date must error")
	}
}

func TestExternalRCAOtherPartsByteIdentical(t *testing.T) {
	tpl := readRCAZip(t, externalRCATemplate)
	reg, dir := rcaTestRegistry(t, "2026-11-05")
	got := readRCAZip(t, generatedRCADoc(t, reg, dir, rcaSample))
	for _, name := range []string{"word/media/image1.png", "word/footer1.xml", "word/styles.xml", "word/numbering.xml"} {
		if !bytes.Equal(tpl[name], got[name]) {
			t.Errorf("%s changed — only word/document.xml may differ", name)
		}
	}
	core := string(got["docProps/core.xml"])
	if strings.Contains(core, "dandalina") {
		t.Error("template creator PII leaked into the generated file")
	}
}
