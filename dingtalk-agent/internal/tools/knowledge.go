package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"techrisk-dashboard/dingtalk-agent/internal/kb"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// RegisterKnowledgeTools registers search_knowledge over the RAG index and
// exposes it to the bot (file ingestion). Nil index = never called.
func (r *Registry) RegisterKnowledgeTools(ix *kb.Index) {
	r.kbIndex = ix
	r.Register(searchKnowledgeDef(), r.handleSearchKnowledge)
}

// KBIndex exposes the knowledge index to the bot (file uploads ingest into it).
func (r *Registry) KBIndex() *kb.Index { return r.kbIndex }

func searchKnowledgeDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "search_knowledge",
			Description: "Search the team knowledge base: SOPs, procedures and reference documents users uploaded in chat. " +
				"Returns the most relevant passages with their document names — use them to answer, quoting the text.",
			Parameters: schema(
				map[string]any{
					"query": map[string]any{"type": "string", "description": "What to look up, e.g. 'payment gateway restart procedure'"},
				},
				[]string{"query"},
			),
		},
	}
}

func (r *Registry) handleSearchKnowledge(argsJSON string) (string, error) {
	var a struct {
		Query string `json:"query"`
	}
	if err := json.Unmarshal([]byte(argsJSON), &a); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	a.Query = strings.TrimSpace(a.Query)
	if a.Query == "" || len(a.Query) > 500 {
		return "", fmt.Errorf("query must be 1-500 characters")
	}
	hits := r.kbIndex.Search(context.Background(), a.Query, searchTopK)
	if len(hits) == 0 {
		return "No knowledge-base documents match. If none were uploaded yet, say that plainly.", nil
	}
	var sb strings.Builder
	for i, h := range hits {
		fmt.Fprintf(&sb, "### match %d — %s (part %d)\n%s\n\n", i+1, h.Doc, h.Seq+1, h.Text)
	}
	return sb.String(), nil
}

// searchTopK caps the passages one search returns.
const searchTopK = 6
