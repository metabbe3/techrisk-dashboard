package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"regexp"
	"strings"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// Skills bridge: the dashboard's Filament-managed skill library (plus its War
// Room personas) surfaces to the agent as list_skills / load_skill. On-demand
// progressive disclosure — the catalog is metadata-only; a body loads when the
// model needs it (48k cap server-side, mirrored by the tool cap below).
// Same Laravel surface family as the corpus route.

// skillNameRe matches the server's route pattern: 1-64 name characters.
// Personas ride the same shape as role-<role_key>.
var skillNameRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)

func listSkillsDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "list_skills",
			Description: "Catalog of the team's methodology skills and persona profiles (dashboard-managed). " +
				"Call it when a question asks for a methodology, framework, checklist, or a specific way of " +
				"working (RCA templates, postmortem formats, analysis playbooks). Each entry: name, display_name, " +
				"description, domain (role = persona profiles). No content — load_skill the one that fits.",
			Parameters: schema(map[string]any{}, []string{}),
		},
	}
}

func loadSkillDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "load_skill",
			Description: "One skill's full body (or a role-<key> persona: its system prompt plus the NAMES of " +
				"its attached skills — load_skill each of those separately). Load at most a few per turn; " +
				"follow the skill's procedure when answering.",
			Parameters: schema(
				map[string]any{
					"name": map[string]any{"type": "string", "description": "Exact name from list_skills, e.g. incident-priority or role-sre"},
				},
				[]string{"name"},
			),
		},
	}
}

func (c *dashboardClient) handleListSkills(ctx context.Context, _ struct{}) (string, error) {
	env, err := c.get(ctx, "/skills")
	if err != nil {
		return "", err
	}
	var entries []struct {
		Name        string `json:"name"`
		DisplayName string `json:"display_name"`
		Description string `json:"description"`
		Domain      string `json:"domain"`
	}
	if err := json.Unmarshal(env.Data, &entries); err != nil {
		return "", fmt.Errorf("unexpected skills payload: %w", err)
	}
	if len(entries) == 0 {
		return "No skills are published yet (the dashboard skill library is empty).", nil
	}
	var b strings.Builder
	for _, e := range entries {
		fmt.Fprintf(&b, "- %s (%s) — %s\n", e.Name, e.Domain, e.Description)
		if e.DisplayName != "" && e.DisplayName != e.Name {
			fmt.Fprintf(&b, "  display: %s\n", e.DisplayName)
		}
	}
	return b.String(), nil
}

func (c *dashboardClient) handleLoadSkill(ctx context.Context, args struct {
	Name string `json:"name"`
}) (string, error) {
	name := strings.TrimSpace(args.Name)
	if !skillNameRe.MatchString(name) {
		return "", fmt.Errorf("name must be 1-64 chars of letters, digits, _ or - (from list_skills)")
	}

	env, err := c.get(ctx, "/skills/"+name)
	if err != nil {
		return "", err
	}
	var page struct {
		Name      string `json:"name"`
		Content   string `json:"content"`
		Truncated bool   `json:"truncated"`
		Chars     int    `json:"chars"`
	}
	if err := json.Unmarshal(env.Data, &page); err != nil {
		return "", fmt.Errorf("unexpected skill payload: %w", err)
	}
	if page.Truncated {
		return page.Content + fmt.Sprintf("\n\n[truncated — %d chars shown]", page.Chars), nil
	}
	return page.Content, nil
}
