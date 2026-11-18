package tools

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"
	"testing"
)

// execute runs a tool by name against a registry with the arg map as JSON.
func execute(reg *Registry, name string, args map[string]any) (string, error) {
	raw, _ := json.Marshal(args)
	return reg.Execute(context.Background(), name, string(raw))
}

// The skills bridge: the dashboard's Filament-managed skill library (and its
// War Room personas) surface to the agent as list_skills / load_skill. The
// catalog is metadata-only; the body loads on demand, 48k-capped server-side.
func TestListSkillsReturnsCatalog(t *testing.T) {
	var gotPath string
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		envelope(w, []map[string]any{
			{"name": "incident-priority", "display_name": "Incident Priority", "description": "How to prioritize.", "domain": "incident_management", "frameworks": []string{}, "tags": []string{}},
			{"name": "role-sre", "display_name": "SRE Engineer", "description": "Site reliability analysis.", "domain": "role", "frameworks": []string{}, "tags": []string{}},
		}, nil)
	})

	out, _ := execute(reg, "list_skills", map[string]any{})
	if gotPath != "/skills" {
		t.Errorf("catalog fetched from %q", gotPath)
	}
	for _, want := range []string{"incident-priority", "incident_management", "role-sre", "Site reliability analysis."} {
		if !strings.Contains(out, want) {
			t.Errorf("catalog missing %q:\n%s", want, out)
		}
	}
}

func TestLoadSkillFetchesOneBody(t *testing.T) {
	var gotPath string
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		envelope(w, map[string]any{"name": "incident-priority", "content": "# Priority\n\nP1 first.\n", "truncated": false, "chars": 18}, nil)
	})

	out, _ := execute(reg, "load_skill", map[string]any{"name": "incident-priority"})
	if gotPath != "/skills/incident-priority" {
		t.Errorf("body fetched from %q", gotPath)
	}
	if !strings.Contains(out, "P1 first.") {
		t.Errorf("body missing content:\n%s", out)
	}
}

func TestLoadSkillNotesTruncation(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, map[string]any{"name": "big", "content": strings.Repeat("x", 100), "truncated": true, "chars": 48000}, nil)
	})
	out, _ := execute(reg, "load_skill", map[string]any{"name": "big"})
	if !strings.Contains(out, "truncated") {
		t.Errorf("truncated flag must be visible to the model:\n%s", out)
	}
}

func TestLoadSkillValidatesNameLocally(t *testing.T) {
	hits := 0
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		hits++
		envelope(w, map[string]any{}, nil)
	})

	for _, bad := range []string{"", "a b", strings.Repeat("x", 65), "../secret"} {
		out, _ := execute(reg, "load_skill", map[string]any{"name": bad})
		if !strings.Contains(out, "Error executing") {
			t.Errorf("name %q must be rejected locally, got: %q", bad, out)
		}
	}
	if hits != 0 {
		t.Errorf("invalid names must fail before any HTTP round trip, got %d requests", hits)
	}
}

func TestLoadSkillPersonaNamePasses(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, map[string]any{"name": "role-sre", "content": "## Persona system prompt\n\nBe the SRE.", "truncated": false, "chars": 40}, nil)
	})
	out, _ := execute(reg, "load_skill", map[string]any{"name": "role-sre"})
	if !strings.Contains(out, "Be the SRE.") {
		t.Errorf("persona bundle content missing:\n%s", out)
	}
}
