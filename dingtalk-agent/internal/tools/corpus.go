package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"net/url"
	"strings"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// corpusPage mirrors CorpusController's success payload.
type corpusPage struct {
	Path      string `json:"path"`
	Content   string `json:"content"`
	Truncated bool   `json:"truncated"`
	Chars     int    `json:"chars"`
}

func readCorpusPageDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "read_corpus_page",
			Description: "One page from the incident memory corpus (incident reports + compiled topic pattern " +
				"pages). Read topics/index.md first to see what exists, then open a page. path is relative " +
				"to the corpus root, e.g. topics/payments.md or 20401106_IN_0042/incident.md.",
			Parameters: schema(
				map[string]any{
					"path": map[string]any{"type": "string", "description": "Relative corpus path, e.g. topics/index.md"},
				},
				[]string{"path"},
			),
		},
	}
}

// validateCorpusPath mirrors the server's rules client-side so bad paths fail
// fast without a round trip: relative, no .., no backslash, markdown only.
func validateCorpusPath(p string) (string, error) {
	p = strings.TrimSpace(p)
	if p == "" {
		return "", fmt.Errorf("path is required (e.g. topics/index.md)")
	}
	if len(p) > 200 {
		return "", fmt.Errorf("path too long (max 200 chars)")
	}
	if strings.Contains(p, "..") {
		return "", fmt.Errorf("path must not contain '..' — it stays inside the corpus")
	}
	if strings.Contains(p, "\\") {
		return "", fmt.Errorf("path must not contain backslashes")
	}
	if strings.HasPrefix(p, "/") {
		return "", fmt.Errorf("path must be relative to the corpus root")
	}
	if !strings.HasSuffix(p, ".md") {
		return "", fmt.Errorf("path must end in .md")
	}
	return p, nil
}

func (c *dashboardClient) handleReadCorpusPage(ctx context.Context, args struct {
	Path string `json:"path"`
}) (string, error) {
	p, err := validateCorpusPath(args.Path)
	if err != nil {
		return "", err
	}

	env, err := c.get(ctx, "/v1/corpus?path="+url.QueryEscape(p))
	if err != nil {
		return "", err
	}

	var page corpusPage
	if err := json.Unmarshal(env.Data, &page); err != nil {
		return "", fmt.Errorf("unexpected corpus payload: %w", err)
	}
	if page.Truncated {
		return page.Content + fmt.Sprintf("\n\n[truncated — %d chars shown; ask for a narrower path or topic]", page.Chars), nil
	}
	return page.Content, nil
}
