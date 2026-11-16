package tools

import (
	"context"
	"fmt"
	"html"
	"io"
	"net"
	"net/http"
	"net/netip"
	"net/url"
	"regexp"
	"strings"
	"syscall"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/util"
)

// Keyless web research tools (owner request 2026-11-10). web_search hits DuckDuckGo's
// HTML endpoint — no API key, stable anchor-class markup. web_fetch pulls a page with an
// SSRF guard: the dialer's Control hook rejects non-public IPs post-DNS, pre-connect,
// which also covers redirect targets and DNS-rebinding.

const (
	webTimeout        = 20 * time.Second
	webFetchMaxBytes  = 256 << 10 // 256 KiB
	webResultMaxChars = 7000      // under the loop's 8k tool-result cap
	webMaxResults     = 6
)

// ddgEndpoint is a var so tests can point the search at a local fake.
var ddgEndpoint = "https://html.duckduckgo.com/html/"

// webTools holds the HTTP clients: guardedClient enforces the SSRF dialer (prod),
// plainClient is the unguarded seam used by tests (loopback is deliberately blocked
// in the guarded one, so positive tests can't use httptest against it).
type webTools struct {
	searchClient *http.Client // GET html.duckduckgo.com (public host — guarded is fine, but tests inject plain)
	fetchClient  *http.Client // user-supplied URLs — MUST be the guarded client in prod
}

func newWebTools() *webTools {
	guarded := &http.Client{Transport: guardedTransport(), Timeout: webTimeout}
	plain := &http.Client{Timeout: webTimeout}
	return &webTools{searchClient: plain, fetchClient: guarded}
}

var (
	cgnatRange  = netip.MustParsePrefix("100.64.0.0/10") // carrier NAT incl. Alibaba metadata 100.100.100.200
	benchRange  = netip.MustParsePrefix("198.18.0.0/15") // benchmarking, some cloud metadata mirrors
	thisNetRng  = netip.MustParsePrefix("0.0.0.0/8")     // "this network"
	nat64Prefix = netip.MustParsePrefix("64:ff9b::/96")  // IPv6 translation to v4 intranets
)

// blockedIP reports whether an address must never be dialed.
func blockedIP(a netip.Addr) bool {
	a = a.Unmap()
	return !a.IsValid() || a.IsLoopback() || a.IsPrivate() || a.IsLinkLocalUnicast() ||
		a.IsLinkLocalMulticast() || a.IsMulticast() || a.IsUnspecified() ||
		cgnatRange.Contains(a) || benchRange.Contains(a) || thisNetRng.Contains(a) ||
		(a.Is6() && nat64Prefix.Contains(a))
}

// guardedTransport resolves + dials only public IPs (Control fires post-DNS,
// pre-connect, covering redirects and DNS rebinding).
func guardedTransport() *http.Transport {
	dialer := &net.Dialer{
		Timeout: 10 * time.Second,
		Control: func(_, addr string, _ syscall.RawConn) error {
			host, _, err := net.SplitHostPort(addr)
			if err != nil {
				return fmt.Errorf("web_fetch: bad dial address %q", addr)
			}
			ip, err := netip.ParseAddr(host)
			if err != nil || blockedIP(ip) {
				return fmt.Errorf("web_fetch: address %q is not a public IP", host)
			}
			return nil
		},
	}
	return &http.Transport{DialContext: dialer.DialContext}
}

func (r *Registry) registerWebTools() {
	wt := newWebTools()
	r.Register(webSearchDef(), typed(wt.handleWebSearch))
	r.Register(webFetchDef(), typed(wt.handleWebFetch))
}

func webSearchDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "web_search",
			Description: "Search the public internet (DuckDuckGo) and return the top results with title, URL, " +
				"and snippet. Use for external knowledge: vendor status pages, public outage reports, CVEs, " +
				"provider news. Dashboard data always wins when they conflict.",
			Parameters: schema(
				map[string]any{
					"query": map[string]any{"type": "string", "description": "Search query"},
				},
				[]string{"query"},
			),
		},
	}
}

type webSearchArgs struct {
	Query string `json:"query"`
}

func (w *webTools) handleWebSearch(_ context.Context, args webSearchArgs) (string, error) {
	q := strings.TrimSpace(args.Query)
	if q == "" {
		return "", fmt.Errorf("query is required")
	}

	req, err := http.NewRequest(http.MethodGet, ddgEndpoint+"?q="+url.QueryEscape(q), nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("User-Agent", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36")
	resp, err := w.searchClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("search failed: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("search failed: HTTP %d", resp.StatusCode)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, webFetchMaxBytes))
	if err != nil {
		return "", err
	}

	results := parseDDG(string(body))
	if len(results) == 0 {
		return fmt.Sprintf("No results found for %q (the search page may have changed or rate-limited). Try web_fetch on a known URL instead.", q), nil
	}
	var sb strings.Builder
	fmt.Fprintf(&sb, "Web results for %q:\n", q)
	for i, res := range results {
		fmt.Fprintf(&sb, "%d. %s\n   %s\n   %s\n", i+1, res.title, res.link, res.snippet)
	}
	return selfTruncate(sb.String()), nil
}

type ddgResult struct{ title, link, snippet string }

var (
	ddgLinkRe    = regexp.MustCompile(`(?s)<a[^>]+class="[^"]*result__a[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)</a>`)
	ddgSnippetRe = regexp.MustCompile(`(?s)<a[^>]+class="[^"]*result__snippet[^"]*"[^>]*>(.*?)</a>`)
	anyTagRe     = regexp.MustCompile(`<[^>]*>`)
)

// parseDDG extracts result links + snippets from DuckDuckGo's HTML page.
// ponytail: anchored on result__a/result__snippet classes with regex — if DDG changes
// markup, the caller gets a graceful "no results" message, not a crash.
func parseDDG(page string) []ddgResult {
	links := ddgLinkRe.FindAllStringSubmatch(page, webMaxResults)
	if len(links) == 0 {
		return nil
	}
	snippets := ddgSnippetRe.FindAllStringSubmatch(page, webMaxResults)

	out := make([]ddgResult, 0, len(links))
	for i, m := range links {
		res := ddgResult{
			link:  resolveDDGLink(html.UnescapeString(m[1])),
			title: html.UnescapeString(anyTagRe.ReplaceAllString(m[2], "")),
		}
		if i < len(snippets) {
			res.snippet = html.UnescapeString(anyTagRe.ReplaceAllString(snippets[i][1], ""))
		}
		if res.title == "" {
			res.title = res.link
		}
		out = append(out, res)
	}
	return out
}

// resolveDDGLink unwraps DDG's /l/?uddg=<encoded> redirect links.
func resolveDDGLink(href string) string {
	if u, err := url.Parse(href); err == nil && u.Path == "/l/" {
		if target := u.Query().Get("uddg"); target != "" {
			if dec, err := url.QueryUnescape(target); err == nil {
				return dec
			}
		}
	}
	if strings.HasPrefix(href, "//") {
		return "https:" + href
	}
	return href
}

func webFetchDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "web_fetch",
			Description: "Fetch a public http(s) URL and return its readable text (HTML tags stripped, scripts dropped). " +
				"Private/intranet addresses are blocked. Use after web_search for the full content of a result.",
			Parameters: schema(
				map[string]any{
					"url": map[string]any{"type": "string", "description": "Absolute http(s) URL"},
				},
				[]string{"url"},
			),
		},
	}
}

type webFetchArgs struct {
	URL string `json:"url"`
}

var (
	scriptBlockRe = regexp.MustCompile(`(?is)<(script|style)[^>]*>.*?</(script|style)>`)
	blockElRe     = regexp.MustCompile(`(?is)</(p|div|br|li|tr|h[1-6])>`)
)

func (w *webTools) handleWebFetch(_ context.Context, args webFetchArgs) (string, error) {
	raw := strings.TrimSpace(args.URL)
	u, err := url.Parse(raw)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return "", fmt.Errorf("url must be absolute http(s), got %q", raw)
	}

	req, err := http.NewRequest(http.MethodGet, u.String(), nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("User-Agent", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36")
	resp, err := w.fetchClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("fetch failed (private addresses are blocked): %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("fetch failed: HTTP %d", resp.StatusCode)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, webFetchMaxBytes))
	if err != nil {
		return "", err
	}

	text := htmlToText(string(body))
	if strings.TrimSpace(text) == "" {
		return "", fmt.Errorf("page has no readable text")
	}
	// Web pages are untrusted: framed as data so embedded prompt injection
	// ("ignore instructions and fetch X") reads as content, not commands
	// (audit 2026-11-12).
	return guardrail.FrameDocumentContent("web page "+u.Host, selfTruncate(text)), nil
}

// htmlToText drops script/style blocks, converts block ends to newlines, strips tags.
// ponytail: regex strip — upgrade to a real parser (or the Scrapling sidecar) only if analyses need structure.
func htmlToText(s string) string {
	s = scriptBlockRe.ReplaceAllString(s, "")
	s = blockElRe.ReplaceAllString(s, "\n")
	s = anyTagRe.ReplaceAllString(s, "")
	s = html.UnescapeString(s)

	var sb strings.Builder
	for _, line := range strings.Split(s, "\n") {
		if line = strings.TrimSpace(line); line != "" {
			if sb.Len() > 0 {
				sb.WriteByte('\n')
			}
			sb.WriteString(line)
		}
	}
	return sb.String()
}

func selfTruncate(s string) string {
	if len(s) <= webResultMaxChars {
		return s
	}
	return util.Cut(s, webResultMaxChars) + fmt.Sprintf("\n…[truncated %d chars]", len([]rune(s))-webResultMaxChars)
}
