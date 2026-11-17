package tools

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/kb"
)

// The skills/ docs net: every registered tool must be listed in some
// skills/<family>/SKILL.md frontmatter. A tool that skips the doc fails HERE,
// not in live chat (the 2026-11-12 "shipped untaught" lesson — the checklist
// lives in skills/README.md).
func TestSkillDocsCoverRegistry(t *testing.T) {
	reg := NewRegistry(&config.Config{WebToolsEnabled: true, NotionToolsEnabled: true})
	reg.RegisterKnowledgeTools(kb.NewIndex(t.TempDir(), "m", sameEmb{}))

	// ponytail: leader tools hand-listed — RegisterLeaderTools needs a live
	// mesh.Server; wire one in if this family grows.
	leaderTools := []string{
		"delegate_task", "list_workers", "mission_status", "cancel_mission",
		"collect_results", "set_mission_result", "mesh_info",
	}

	want := map[string]bool{}
	for _, def := range reg.GetDefinitions() {
		want[def.Function.Name] = false
	}
	for _, name := range leaderTools {
		if _, dup := want[name]; dup {
			t.Errorf("leader tool %q is also registered solo — fix the net", name)
		}
		want[name] = false
	}
	if len(want) == 0 {
		t.Fatal("no tools registered — the net is vacuous")
	}

	entries, err := os.ReadDir("../../skills")
	if err != nil {
		t.Fatalf("cannot read skills/: %v (run from internal/tools)", err)
	}
	var coveredFolders []string
	for _, e := range entries {
		if !e.IsDir() {
			continue
		}
		raw, err := os.ReadFile(filepath.Join("../../skills", e.Name(), "SKILL.md"))
		if err != nil {
			t.Errorf("skills/%s: no SKILL.md (convention requires one per skill folder)", e.Name())
			continue
		}
		doc := string(raw)
		for _, key := range []string{"name", "status", "tools", "gate", "prompt", "files"} {
			if !regexp.MustCompile(`(?m)^` + key + `:`).MatchString(doc) {
				t.Errorf("skills/%s/SKILL.md: frontmatter missing %q key (template in skills/README.md)", e.Name(), key)
			}
		}
		m := regexp.MustCompile(`(?m)^tools:\s*\[(.*?)\]\s*$`).FindStringSubmatch(doc)
		if m == nil {
			t.Errorf("skills/%s/SKILL.md: no tools: [a, b] frontmatter line", e.Name())
			continue
		}
		coveredFolders = append(coveredFolders, e.Name())
		for _, name := range strings.Split(m[1], ",") {
			if name = strings.TrimSpace(name); name != "" {
				want[name] = true
			}
		}
	}
	if len(coveredFolders) == 0 {
		t.Fatal("no SKILL.md found anywhere under skills/ — the convention is not in effect")
	}

	var missing []string
	for name, ok := range want {
		if !ok {
			missing = append(missing, name)
		}
	}
	if len(missing) > 0 {
		t.Errorf("registered tool(s) not documented in any skills/*/SKILL.md: %s — add a folder + SKILL.md (checklist: skills/README.md step 8)", strings.Join(missing, ", "))
	}
}
