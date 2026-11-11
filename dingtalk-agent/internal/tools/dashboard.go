package tools

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// dashboardClient issues GET requests against the read-only Laravel API.
type dashboardClient struct {
	base  string
	token string
	http  *http.Client
}

func newDashboardClient(cfg *config.Config) *dashboardClient {
	return &dashboardClient{
		base:  cfg.DashboardAPIBase,
		token: cfg.DashboardAPIToken,
		http:  &http.Client{Timeout: 30 * time.Second},
	}
}

// apiEnvelope mirrors the Laravel ApiResponser shape: {code, status, message, data, meta?}.
type apiEnvelope struct {
	Code    int             `json:"code"`
	Status  string          `json:"status"`
	Message string          `json:"message"`
	Data    json.RawMessage `json:"data"`
	Meta    *struct {
		Total    int  `json:"total"`
		Limit    any  `json:"limit"`
		Offset   int  `json:"offset"`
		Returned int  `json:"returned"`
		HasMore  bool `json:"has_more"`
	} `json:"meta"`
}

// get performs a GET on path (must start with "/") and returns the decoded envelope.
func (c *dashboardClient) get(ctx context.Context, path string) (*apiEnvelope, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.base+path, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+c.token)

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("dashboard api unreachable: %w", err)
	}
	defer resp.Body.Close()

	return c.decode(resp)
}

// post performs a POST with a JSON payload on path and returns the decoded envelope.
func (c *dashboardClient) post(ctx context.Context, path string, payload any) (*apiEnvelope, error) {
	data, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.base+path, bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("dashboard api unreachable: %w", err)
	}
	defer resp.Body.Close()

	return c.decode(resp)
}

// decode maps an HTTP response onto the envelope with the API's error contract.
func (c *dashboardClient) decode(resp *http.Response) (*apiEnvelope, error) {
	body, err := io.ReadAll(io.LimitReader(resp.Body, 2<<20)) // 2 MiB ceiling
	if err != nil {
		return nil, fmt.Errorf("failed to read dashboard api response: %w", err)
	}

	var env apiEnvelope
	if err := json.Unmarshal(body, &env); err != nil {
		return nil, fmt.Errorf("dashboard api returned invalid JSON (HTTP %d)", resp.StatusCode)
	}

	switch {
	case resp.StatusCode == http.StatusUnauthorized:
		return nil, fmt.Errorf("dashboard api rejected the token (HTTP 401) — token missing, invalid or expired")
	case resp.StatusCode == http.StatusForbidden:
		// Pass the server's message through — the generic wrap alone made the
		// LLM blame Notion integration permissions when the token lacked an
		// endpoint ability (prod 2026-11-11).
		msg := env.Message
		if msg == "" {
			msg = "token lacks the required endpoint ability or user lacks api access"
		}
		return nil, fmt.Errorf("dashboard api forbidden (HTTP 403): %s — tick the missing endpoint ability on the API token in Filament → API Tokens, or use an empty allowlist", msg)
	case resp.StatusCode == http.StatusTooManyRequests:
		return nil, fmt.Errorf("dashboard api rate limit hit (HTTP 429) — wait and retry later")
	case resp.StatusCode == http.StatusNotFound:
		return nil, fmt.Errorf("not found (HTTP 404): %s", env.Message)
	case resp.StatusCode == http.StatusUnprocessableEntity:
		return nil, fmt.Errorf("invalid filter (HTTP 422): %s", env.Message)
	case resp.StatusCode >= 500:
		return nil, fmt.Errorf("dashboard api server error (HTTP %d): %s", resp.StatusCode, env.Message)
	case resp.StatusCode < 200 || resp.StatusCode >= 300:
		return nil, fmt.Errorf("dashboard api error (HTTP %d): %s", resp.StatusCode, env.Message)
	}

	return &env, nil
}

// incidentNoPattern constrains what may be interpolated into a URL path segment.
var incidentNoPattern = regexp.MustCompile(`^[A-Za-z0-9_-]+$`)

func sanitizeRef(ref string) (string, error) {
	ref = strings.TrimSpace(ref)
	if ref == "" || !incidentNoPattern.MatchString(ref) {
		return "", fmt.Errorf("invalid incident reference %q: expected an incident number like 20250115_IN_1234 or a numeric id", ref)
	}
	return ref, nil
}

// datePattern enforces YYYY-MM-DD (the API validates too; failing early gives the LLM a clearer error).
var datePattern = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)

func normalizeDate(name, val string) (string, error) {
	if !datePattern.MatchString(val) {
		return "", fmt.Errorf("%s must be YYYY-MM-DD, got %q", name, val)
	}
	if _, err := time.Parse("2006-01-02", val); err != nil {
		return "", fmt.Errorf("%s is not a real calendar date: %q", name, val)
	}
	return val, nil
}

// pairDates works around the API's rule that start_date/end_date only apply when BOTH are present
// (IncidentController silently ignores a lone bound): a missing side is filled in so the intended
// range is never widened to all-time.
func pairDates(start, end string) (string, string, error) {
	today := time.Now().Format("2006-01-02")
	switch {
	case start != "" && end == "":
		end = today
	case start == "" && end != "":
		start = "1970-01-01"
	}
	if start != "" {
		var err error
		if start, err = normalizeDate("start_date", start); err != nil {
			return "", "", err
		}
	}
	if end != "" {
		var err error
		if end, err = normalizeDate("end_date", end); err != nil {
			return "", "", err
		}
	}
	if start != "" && end != "" && start > end {
		return "", "", fmt.Errorf("start_date (%s) is after end_date (%s)", start, end)
	}
	return start, end, nil
}

func (r *Registry) registerDashboardTools(cfg *config.Config) {
	client := newDashboardClient(cfg)
	r.dash = client

	r.Register(searchIncidentDef(), client.handleSearchIncidents)
	r.Register(getIncidentDef(), client.handleGetIncident)
	r.Register(getIncidentMarkdownDef(), client.handleGetIncidentMarkdown)
}

// GetIncidentByNo fetches one incident's full JSON by its incident number
// (the watch binder + poller use it to resolve and diff).
func (c *dashboardClient) GetIncidentByNo(ctx context.Context, no string) (json.RawMessage, error) {
	no, err := sanitizeRef(no)
	if err != nil {
		return nil, err
	}
	env, err := c.get(ctx, "/incidents-by-no/"+url.PathEscape(no))
	if err != nil {
		return nil, err
	}
	return env.Data, nil
}

// Search runs a raw GET (path with query string) and returns the envelope —
// the watch poller's updated_since sweep uses it.
func (c *dashboardClient) Search(ctx context.Context, path string) (*apiEnvelope, error) {
	return c.get(ctx, path)
}

func searchIncidentDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "search_incidents",
			Description: "Search or count dashboard records (incidents AND issues). Response has meta.total (total matches — " +
				"THE source for any count question: request limit=1 and read meta.total) and data (returned rows). " +
				"IMPORTANT: meta.total is a RAW count — it includes severity G, 'Non Incident', and Issue rows. " +
				"Official incident count = total(classification=Incident) − total(classification=Incident,severity=G) − " +
				"total(classification=Incident,severity=Non Incident) as three limit=1 calls. " +
				"Dates are YYYY-MM-DD; a range needs both start and end (a lone start_date means 'until today'). " +
				"severity: P1|P2|P3|P4 (tech) | X1|X2|X3|X4 (non-tech) | G (glitch) | Non Incident. " +
				"status: Open|In progress|Finalization|Completed. classification: Incident|Issue. " +
				"fund_status: Non fundLoss|Confirmed loss|Potential recovery|Fully recovered|Non Tech Loss. " +
				"type: Tech|Non-tech. tags: label names (e.g. Outlier).",
			Parameters: schema(
				map[string]any{
					"severity":                map[string]any{"type": "string", "description": "Severity filter (P1-P4, X1-X4, G, 'Non Incident')"},
					"status":                  map[string]any{"type": "string", "description": "Incident status: Open, In progress, Finalization, Completed"},
					"classification":          map[string]any{"type": "string", "description": "Incident or Issue"},
					"fund_status":             map[string]any{"type": "string", "description": "Non fundLoss, Confirmed loss, Potential recovery, Fully recovered, or Non Tech Loss"},
					"type":                    map[string]any{"type": "string", "description": "Tech or Non-tech"},
					"tags":                    map[string]any{"type": "string", "description": "Comma-separated label names, e.g. payment,database"},
					"search":                  map[string]any{"type": "string", "description": "Keyword (min 2 chars) matched against title, summary, and incident number"},
					"start_date":              map[string]any{"type": "string", "description": "Range start (inclusive), YYYY-MM-DD"},
					"end_date":                map[string]any{"type": "string", "description": "Range end (inclusive), YYYY-MM-DD"},
					"updated_since":           map[string]any{"type": "string", "description": "Only rows UPDATED at/after this timestamp (RFC3339 or YYYY-MM-DD) — catches edits, unlike start/end_date which filter incident_date. Use for 'what changed since X' and digest windows"},
					"min_fund_loss":           map[string]any{"type": "number", "description": "Minimum fund loss in IDR"},
					"max_fund_loss":           map[string]any{"type": "number", "description": "Maximum fund loss in IDR"},
					"min_potential_fund_loss": map[string]any{"type": "number", "description": "Minimum potential fund loss in IDR"},
					"max_potential_fund_loss": map[string]any{"type": "number", "description": "Maximum potential fund loss in IDR"},
					"limit":                   map[string]any{"type": "integer", "description": "Rows to return, 1-100 (default 20). Use 1 when you only need meta.total"},
					"offset":                  map[string]any{"type": "integer", "description": "Rows to skip for pagination"},
				},
				nil,
			),
		},
	}
}

type searchArgs struct {
	Severity         string   `json:"severity"`
	Status           string   `json:"status"`
	Classification   string   `json:"classification"`
	FundStatus       string   `json:"fund_status"`
	Type             string   `json:"type"`
	Tags             string   `json:"tags"`
	Search           string   `json:"search"`
	StartDate        string   `json:"start_date"`
	EndDate          string   `json:"end_date"`
	UpdatedSince     string   `json:"updated_since"`
	MinFundLoss      *float64 `json:"min_fund_loss"`
	MaxFundLoss      *float64 `json:"max_fund_loss"`
	MinPotentialLoss *float64 `json:"min_potential_fund_loss"`
	MaxPotentialLoss *float64 `json:"max_potential_fund_loss"`
	Limit            *int     `json:"limit"`
	Offset           int      `json:"offset"`
}

const searchLimitCeiling = 100
const searchLimitDefault = 20

func (c *dashboardClient) handleSearchIncidents(argsJSON string) (string, error) {
	var args searchArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}

	start, end, err := pairDates(args.StartDate, args.EndDate)
	if err != nil {
		return "", err
	}

	limit := searchLimitDefault
	if args.Limit != nil {
		limit = *args.Limit
	}
	if limit < 1 {
		limit = 1
	}
	if limit > searchLimitCeiling {
		limit = searchLimitCeiling // ponytail: hard ceiling, the API allows 500 — a big limit only burns context
	}
	if args.Offset < 0 {
		args.Offset = 0
	}

	q := url.Values{}
	q.Set("limit", strconv.Itoa(limit))
	q.Set("offset", strconv.Itoa(args.Offset))
	setIfNotEmpty := func(key, val string) {
		if val != "" {
			q.Set(key, val)
		}
	}
	setIfNotEmpty("severity", args.Severity)
	setIfNotEmpty("incident_status", args.Status)
	setIfNotEmpty("classification", args.Classification)
	setIfNotEmpty("fund_status", args.FundStatus)
	setIfNotEmpty("type", args.Type)
	setIfNotEmpty("tags", args.Tags)
	if len(strings.TrimSpace(args.Search)) >= 2 {
		q.Set("search", strings.TrimSpace(args.Search))
	}
	setIfNotEmpty("start_date", start)
	setIfNotEmpty("end_date", end)
	setIfNotEmpty("updated_since", args.UpdatedSince)
	setNum := func(key string, v *float64) {
		if v != nil && *v >= 0 {
			q.Set(key, strconv.FormatFloat(*v, 'f', -1, 64))
		}
	}
	setNum("min_fund_loss", args.MinFundLoss)
	setNum("max_fund_loss", args.MaxFundLoss)
	setNum("min_potential_fund_loss", args.MinPotentialLoss)
	setNum("max_potential_fund_loss", args.MaxPotentialLoss)

	env, err := c.get(context.Background(), "/incidents?"+q.Encode())
	if err != nil {
		return "", err
	}

	out := map[string]any{"meta": env.Meta, "data": json.RawMessage(env.Data)}
	encoded, err := json.Marshal(out)
	if err != nil {
		return "", err
	}
	return string(encoded), nil
}

func getIncidentDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "get_incident",
			Description: "Full detail of a single incident (summary, root cause, severity, status, PICs, labels, " +
				"status updates, action improvements, fund loss) by incident number (e.g. 20250115_IN_1234) " +
				"or numeric id. Use search_incidents first when the number is unknown.",
			Parameters: schema(
				map[string]any{
					"no": map[string]any{"type": "string", "description": "Incident number, e.g. 20250115_IN_1234 (preferred)"},
					"id": map[string]any{"type": "integer", "description": "Numeric incident id (alternative to no)"},
				},
				nil,
			),
		},
	}
}

type getIncidentArgs struct {
	No string `json:"no"`
	ID *int   `json:"id"`
}

func (c *dashboardClient) handleGetIncident(argsJSON string) (string, error) {
	var args getIncidentArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}

	var path string
	switch {
	case args.ID != nil && *args.ID > 0:
		path = "/incidents/" + strconv.Itoa(*args.ID)
	case strings.TrimSpace(args.No) != "":
		no, err := sanitizeRef(args.No)
		if err != nil {
			return "", err
		}
		path = "/incidents-by-no/" + url.PathEscape(no)
	default:
		return "", fmt.Errorf("provide either no (incident number) or id (numeric)")
	}

	env, err := c.get(context.Background(), path)
	if err != nil {
		return "", err
	}
	return string(env.Data), nil
}

func getIncidentMarkdownDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "get_incident_markdown",
			Description: "The complete incident report as Markdown (base64-decoded by the tool), including timeline " +
				"and all relations. Use for deep-dive questions after get_incident, or when asked to summarize an incident.",
			Parameters: schema(
				map[string]any{
					"no": map[string]any{"type": "string", "description": "Incident number, e.g. 20250115_IN_1234"},
				},
				[]string{"no"},
			),
		},
	}
}

func (c *dashboardClient) handleGetIncidentMarkdown(argsJSON string) (string, error) {
	var args getIncidentArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}

	no, err := sanitizeRef(args.No)
	if err != nil {
		return "", err
	}

	env, err := c.get(context.Background(), "/incidents-by-no/"+url.PathEscape(no)+"/markdown")
	if err != nil {
		return "", err
	}

	var encoded string
	if err := json.Unmarshal(env.Data, &encoded); err != nil {
		return "", fmt.Errorf("unexpected markdown payload: %w", err)
	}
	markdown, err := base64.StdEncoding.DecodeString(encoded)
	if err != nil {
		return "", fmt.Errorf("failed to base64-decode incident markdown: %w", err)
	}
	return string(markdown), nil
}
