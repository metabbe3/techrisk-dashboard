package config

import (
	"testing"
)

func loadEnv(t *testing.T, set map[string]string) *Config {
	t.Helper()
	// Neutralize knobs first: t.Setenv restores only at test END, so without this
	// a value from an earlier sub-test leaks into later ones (empty = unset for getVal).
	for _, k := range []string{"LLM_MAX_TOKENS", "AI_WAR_ROOM_MAX_OUTPUT_TOKENS", "AGENT_WEB_TOOLS", "AGENT_MAX_ITER", "AGENT_MAX_REPLY_CHARS", "AGENT_AUTO_LEARN"} {
		t.Setenv(k, "")
	}
	for k, v := range set {
		t.Setenv(k, v)
	}
	cfg, err := LoadFromFile("no-such.env")
	if err != nil {
		t.Fatalf("LoadFromFile: %v", err)
	}
	return cfg
}

func TestDefaults(t *testing.T) {
	cfg := loadEnv(t, map[string]string{
		"DINGTALK_CLIENT_ID":     "id",
		"DINGTALK_CLIENT_SECRET": "secret",
		"DASHBOARD_API_TOKEN":    "tok",
	})
	if DefaultMaxIterations != 60 || cfg.MaxIterations != 60 {
		t.Errorf("MaxIterations = %d (default %d), want 60", cfg.MaxIterations, DefaultMaxIterations)
	}
	if DefaultLLMMaxTokens != 16384 || cfg.LLMMaxTokens != 16384 {
		t.Errorf("LLMMaxTokens = %d (default %d), want 16384", cfg.LLMMaxTokens, DefaultLLMMaxTokens)
	}
	if !cfg.WebToolsEnabled {
		t.Error("WebToolsEnabled should default to true")
	}
	if DefaultMaxReplyChars != 8000 || cfg.MaxReplyChars != 8000 {
		t.Errorf("MaxReplyChars = %d (default %d), want 8000", cfg.MaxReplyChars, DefaultMaxReplyChars)
	}
}

func TestMaxTokensSources(t *testing.T) {
	base := map[string]string{
		"DINGTALK_CLIENT_ID": "id", "DINGTALK_CLIENT_SECRET": "s", "DASHBOARD_API_TOKEN": "t",
	}
	if cfg := loadEnv(t, with(base, "LLM_MAX_TOKENS", "32768")); cfg.LLMMaxTokens != 32768 {
		t.Errorf("direct LLM_MAX_TOKENS = %d, want 32768", cfg.LLMMaxTokens)
	}
	if cfg := loadEnv(t, with(base, "AI_WAR_ROOM_MAX_OUTPUT_TOKENS", "65536")); cfg.LLMMaxTokens != 65536 {
		t.Errorf("war-room fallback = %d, want 65536", cfg.LLMMaxTokens)
	}
	if cfg := loadEnv(t, with(base, "LLM_MAX_TOKENS", "not-a-number")); cfg.LLMMaxTokens != 16384 {
		t.Errorf("invalid = %d, want default 16384", cfg.LLMMaxTokens)
	}
	if cfg := loadEnv(t, with(base, "LLM_MAX_TOKENS", "0")); cfg.LLMMaxTokens != 0 {
		t.Errorf("explicit 0 must stay 0 (omit the field), got %d", cfg.LLMMaxTokens)
	}
}

func TestWebToolsKillSwitch(t *testing.T) {
	base := map[string]string{
		"DINGTALK_CLIENT_ID": "id", "DINGTALK_CLIENT_SECRET": "s", "DASHBOARD_API_TOKEN": "t",
	}
	for _, off := range []string{"0", "false", "off", "no", "OFF"} {
		if cfg := loadEnv(t, with(base, "AGENT_WEB_TOOLS", off)); cfg.WebToolsEnabled {
			t.Errorf("AGENT_WEB_TOOLS=%q must disable web tools", off)
		}
	}
	if cfg := loadEnv(t, with(base, "AGENT_WEB_TOOLS", "1")); !cfg.WebToolsEnabled {
		t.Error("AGENT_WEB_TOOLS=1 must enable web tools")
	}
}

func TestMaxIterOverride(t *testing.T) {
	cfg := loadEnv(t, map[string]string{
		"DINGTALK_CLIENT_ID": "id", "DINGTALK_CLIENT_SECRET": "s", "DASHBOARD_API_TOKEN": "t",
		"AGENT_MAX_ITER": "25",
	})
	if cfg.MaxIterations != 25 {
		t.Errorf("MaxIterations = %d, want 25", cfg.MaxIterations)
	}
}

func with(base map[string]string, k, v string) map[string]string {
	out := make(map[string]string, len(base)+1)
	for kk, vv := range base {
		out[kk] = vv
	}
	out[k] = v
	return out
}

func TestAutoLearnKillSwitch(t *testing.T) {
	base := map[string]string{
		"DINGTALK_CLIENT_ID": "id", "DINGTALK_CLIENT_SECRET": "s", "DASHBOARD_API_TOKEN": "t",
	}
	for _, off := range []string{"0", "false", "off", "no"} {
		if cfg := loadEnv(t, with(base, "AGENT_AUTO_LEARN", off)); cfg.AutoLearn {
			t.Errorf("AGENT_AUTO_LEARN=%q must disable learning", off)
		}
	}
	if cfg := loadEnv(t, with(base, "AGENT_AUTO_LEARN", "1")); !cfg.AutoLearn {
		t.Error("AGENT_AUTO_LEARN=1 must enable learning")
	}
	if cfg := loadEnv(t, base); !cfg.AutoLearn {
		t.Error("auto-learning must default on")
	}
}
