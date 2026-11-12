package tools

import (
	"context"
	"fmt"
	"strings"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/kb"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
)

// RegisterKnowledgeTools registers search/save/list/delete over the RAG index
// and exposes it to the bot (file ingestion). Nil index = never called.
func (r *Registry) RegisterKnowledgeTools(ix *kb.Index) {
	r.kbIndex = ix
	r.Register(searchKnowledgeDef(), typed(r.handleSearchKnowledge))
	r.Register(saveKnowledgeDef(), typed(r.handleSaveKnowledge))
	r.Register(listKnowledgeDef(), typed(r.handleListKnowledge))
	r.Register(deleteKnowledgeDef(), typed(r.handleDeleteKnowledge))
}

// KBIndex exposes the knowledge index to the bot (file uploads ingest into it).
func (r *Registry) KBIndex() *kb.Index { return r.kbIndex }

// KB caps for chat-filed content.
const (
	kbTitleChars   = 200
	kbContentChars = 100_000
)

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

func (r *Registry) handleSearchKnowledge(ctx context.Context, a struct {
	Query string `json:"query"`
}) (string, error) {
	a.Query = strings.TrimSpace(a.Query)
	if a.Query == "" || len(a.Query) > 500 {
		return "", fmt.Errorf("query must be 1-500 characters")
	}
	if r.kbIndex == nil {
		return "", fmt.Errorf("knowledge base is not available")
	}
	hits, err := r.kbIndex.Search(ctx, a.Query, searchTopK)
	if err != nil {
		return "", err
	}
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

func saveKnowledgeDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "save_knowledge",
			Description: "File text into the team knowledge base so it persists across conversations — use when a user says " +
				"'remember this' / 'save this' / 'file this', or hands you an important procedure worth keeping. " +
				"Everyone in the chat may file knowledge; keep titles short and descriptive. Filing over an existing title replaces it.",
			Parameters: schema(
				map[string]any{
					"title":   map[string]any{"type": "string", "description": "Short document title, e.g. 'Payment gateway restart SOP'"},
					"content": map[string]any{"type": "string", "description": "The full text to remember"},
				},
				[]string{"title", "content"},
			),
		},
	}
}

func (r *Registry) handleSaveKnowledge(ctx context.Context, a struct {
	Title   string `json:"title"`
	Content string `json:"content"`
}) (string, error) {
	a.Title = strings.TrimSpace(a.Title)
	a.Content = strings.TrimSpace(a.Content)
	if a.Title == "" || len(a.Title) > kbTitleChars {
		return "", fmt.Errorf("title must be 1-%d characters", kbTitleChars)
	}
	if a.Content == "" || len(a.Content) > kbContentChars {
		return "", fmt.Errorf("content must be 1-%d characters", kbContentChars)
	}
	if r.kbIndex == nil {
		return "", fmt.Errorf("knowledge base is not available")
	}
	if err := r.kbIndex.Save(ctx, a.Title, a.Content); err != nil {
		return "", err
	}
	return fmt.Sprintf("Filed %d chars under %q. Use search_knowledge to recall it.", len([]rune(a.Content)), a.Title), nil
}

func listKnowledgeDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "list_knowledge",
			Description: "List every document in the team knowledge base with its size and last update. No parameters.",
			Parameters:  schema(map[string]any{}, nil),
		},
	}
}

func (r *Registry) handleListKnowledge(ctx context.Context, _ struct{}) (string, error) {
	if r.kbIndex == nil {
		return "", fmt.Errorf("knowledge base is not available")
	}
	docs := r.kbIndex.ListDocs()
	if len(docs) == 0 {
		return "The knowledge base is empty.", nil
	}
	var sb strings.Builder
	for _, d := range docs {
		updated := time.Unix(0, d.TS).In(schedule.WIB).Format("2006-01-02")
		fmt.Fprintf(&sb, "- %s — %d part(s), ~%d chars, updated %s\n", d.Doc, d.Chunks, d.Chars, updated)
	}
	return sb.String(), nil
}

func deleteKnowledgeDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "delete_knowledge",
			Description: "Permanently delete one knowledge-base document by its exact title (list_knowledge gives exact titles). " +
				"Confirm the exact title with the user before calling.",
			Parameters: schema(
				map[string]any{
					"title": map[string]any{"type": "string", "description": "Exact document title from list_knowledge"},
				},
				[]string{"title"},
			),
		},
	}
}

func (r *Registry) handleDeleteKnowledge(ctx context.Context, a struct {
	Title string `json:"title"`
}) (string, error) {
	a.Title = strings.TrimSpace(a.Title)
	if a.Title == "" || len(a.Title) > kbTitleChars {
		return "", fmt.Errorf("title must be 1-%d characters", kbTitleChars)
	}
	if r.kbIndex == nil {
		return "", fmt.Errorf("knowledge base is not available")
	}
	n, ok := r.kbIndex.DeleteDoc(a.Title)
	if !ok {
		return "", fmt.Errorf("no document titled %q — call list_knowledge for exact titles", a.Title)
	}
	return fmt.Sprintf("Deleted %q (%d part(s) removed).", a.Title, n), nil
}
