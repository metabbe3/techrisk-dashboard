package config

import (
	"bufio"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

// Config holds all configuration values for the DingTalk risk agent.
type Config struct {
	DingTalkClientID     string
	DingTalkClientSecret string
	DingTalkOpenAPIHost  string   // optional endpoint override (SDK default: api.dingtalk.com)
	DingTalkAllowlist    []string // sender staffIds; empty = everyone in the app visibility range
	DingTalkBotNames     []string // robot display name(s) used to strip @mentions; default "Risk Agent"
	DashboardAPIBase     string   // read-only Laravel API base, e.g. http://nginx/api/v1
	DashboardAPIToken    string   // Sanctum bearer token (abilities: incidents, incidents-by-no, incidents-markdown)
	LLMBaseURL           string   // OpenAI-compatible gateway (maps to the app's AI_API_BASE_URL)
	LLMAPIKey            string
	LLMModel             string
	LLMMaxTokens         int               // max output tokens (War Room parity); 0 = omit the field
	WebToolsEnabled      bool              // web_search / web_fetch registered for the LLM
	NotionToolsEnabled   bool              // read-only Notion doc tools registered for the LLM
	NotionDatabases      map[string]string // named Notion databases (NAME=<id>), seed for the agent's catalog
	AutoLearn            bool              // per-user preference profiles extracted from conversations
	MaxReplyChars        int               // reply cap before truncation (DingTalk markdown size)
	MaxIterations        int
	HistoryTTL           time.Duration // per-conversation memory retention
	DataDir              string        // user registry + persistent history (empty = memory-only)
}

// Default values
const (
	DefaultLLMBaseURL       = "https://api.openai.com/v1"
	DefaultLLMModel         = "glm-5.3-flashx"
	DefaultMaxIterations    = 60    // Hermes parity — owner 2026-11-10
	DefaultLLMMaxTokens     = 16384 // = War Room's code default (config/ai.php)
	DefaultMaxReplyChars    = 8000
	DefaultDashboardAPIBase = "http://nginx/api/v1"
	DefaultEnvFile          = "dingtalk-agent.env"
	DefaultHistoryTTL       = 12 * time.Hour
	DefaultDataDir          = "data"
)

// Load reads configuration from the default env file (if present)
// and overrides it with current process environment variables.
func Load() (*Config, error) {
	return LoadFromFile(DefaultEnvFile)
}

// LoadFromFile reads configuration from a specific env file path.
func LoadFromFile(envPath string) (*Config, error) {
	envMap := make(map[string]string)

	// If file exists, load KEY=VALUE pairs
	if _, err := os.Stat(envPath); err == nil {
		file, err := os.Open(envPath)
		if err != nil {
			return nil, fmt.Errorf("failed to open env file %s: %w", envPath, err)
		}
		defer file.Close()

		scanner := bufio.NewScanner(file)
		for scanner.Scan() {
			line := strings.TrimSpace(scanner.Text())
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}

			idx := strings.Index(line, "=")
			if idx <= 0 {
				continue
			}

			key := strings.TrimSpace(line[:idx])
			val := strings.TrimSpace(line[idx+1:])

			// Remove quotes if present
			if len(val) >= 2 {
				if (val[0] == '"' && val[len(val)-1] == '"') || (val[0] == '\'' && val[len(val)-1] == '\'') {
					val = val[1 : len(val)-1]
				}
			}
			envMap[key] = val
		}

		if err := scanner.Err(); err != nil {
			return nil, fmt.Errorf("error reading env file %s: %w", envPath, err)
		}
	}

	// Helper to get from OS env first, fallback to envMap, fallback to default
	getVal := func(key, defVal string) string {
		if osVal := os.Getenv(key); osVal != "" {
			return osVal
		}
		if mapVal, ok := envMap[key]; ok && mapVal != "" {
			return mapVal
		}
		return defVal
	}

	clientID := getVal("DINGTALK_CLIENT_ID", "")
	clientSecret := getVal("DINGTALK_CLIENT_SECRET", "")
	apiToken := getVal("DASHBOARD_API_TOKEN", "")
	missing := []string{}
	if clientID == "" {
		missing = append(missing, "DINGTALK_CLIENT_ID")
	}
	if clientSecret == "" {
		missing = append(missing, "DINGTALK_CLIENT_SECRET")
	}
	if apiToken == "" {
		missing = append(missing, "DASHBOARD_API_TOKEN")
	}
	if len(missing) > 0 {
		return nil, fmt.Errorf("missing required configuration: %s (see dingtalk-agent/README.md)", strings.Join(missing, ", "))
	}

	// LLM_* wins, then the dashboard's own AI_* env keys (injected via the shared
	// .env file by docker-compose), then hard defaults. AI_API_DEFAULT_MODEL ships
	// placeholder values in some .env files — treat those as unset.
	baseURL := getVal("LLM_BASE_URL", getVal("AI_API_BASE_URL", DefaultLLMBaseURL))
	baseURL = strings.TrimRight(baseURL, "/")

	model := getVal("LLM_MODEL", "")
	if model == "" {
		if candidate := getVal("AI_API_DEFAULT_MODEL", ""); candidate != "" && candidate != "default" && candidate != "SMART-MODEL" {
			model = candidate
		} else {
			model = DefaultLLMModel
		}
	}
	apiKey := getVal("LLM_API_KEY", getVal("AI_API_KEY", ""))

	var allowlist []string
	if raw := getVal("DINGTALK_ALLOWLIST", ""); raw != "" {
		for _, part := range strings.Split(raw, ",") {
			if part = strings.TrimSpace(part); part != "" {
				allowlist = append(allowlist, part)
			}
		}
	}

	botNames := []string{"Risk Agent"}
	if raw := getVal("DINGTALK_BOT_NAMES", ""); raw != "" {
		botNames = nil
		for _, part := range strings.Split(raw, ",") {
			if part = strings.TrimSpace(part); part != "" {
				botNames = append(botNames, part)
			}
		}
	}

	maxIter := DefaultMaxIterations
	if iterStr := getVal("AGENT_MAX_ITER", ""); iterStr != "" {
		if parsed, err := strconv.Atoi(iterStr); err == nil && parsed > 0 {
			maxIter = parsed
		}
	}

	// LLM_MAX_TOKENS wins, then the War Room's own env key (parity on prod), then
	// the default. 0 = omit max_tokens entirely (escape hatch for models that
	// reject it); invalid values fall back like maxIter.
	maxTokens := DefaultLLMMaxTokens
	if tokStr := getVal("LLM_MAX_TOKENS", getVal("AI_WAR_ROOM_MAX_OUTPUT_TOKENS", "")); tokStr != "" {
		if parsed, err := strconv.Atoi(tokStr); err == nil && parsed >= 0 {
			maxTokens = parsed
		}
	}

	webTools := true
	switch strings.ToLower(getVal("AGENT_WEB_TOOLS", "1")) {
	case "0", "false", "off", "no":
		webTools = false
	}

	autoLearn := true
	switch strings.ToLower(getVal("AGENT_AUTO_LEARN", "1")) {
	case "0", "false", "off", "no":
		autoLearn = false
	}

	notionTools := true
	switch strings.ToLower(getVal("AGENT_NOTION_TOOLS", "1")) {
	case "0", "false", "off", "no":
		notionTools = false
	}

	// NOTION_DATABASES="PRD=<id>,TA=<id>" — comma split, first '=' split,
	// trim; malformed entries are skipped.
	var notionDBs map[string]string
	if raw := getVal("NOTION_DATABASES", ""); raw != "" {
		notionDBs = make(map[string]string)
		for _, part := range strings.Split(raw, ",") {
			name, id, ok := strings.Cut(part, "=")
			name, id = strings.TrimSpace(name), strings.TrimSpace(id)
			if ok && name != "" && id != "" {
				notionDBs[name] = id
			}
		}
	}

	maxReply := DefaultMaxReplyChars
	if replyStr := getVal("AGENT_MAX_REPLY_CHARS", ""); replyStr != "" {
		if parsed, err := strconv.Atoi(replyStr); err == nil && parsed >= 500 {
			maxReply = parsed
		}
	}

	historyTTL := DefaultHistoryTTL
	if ttlStr := getVal("AGENT_HISTORY_TTL", ""); ttlStr != "" {
		if parsed, err := time.ParseDuration(ttlStr); err == nil && parsed > 0 {
			historyTTL = parsed
		}
	}

	return &Config{
		DingTalkClientID:     clientID,
		DingTalkClientSecret: clientSecret,
		DingTalkOpenAPIHost:  getVal("DINGTALK_OPEN_API_HOST", ""),
		DingTalkAllowlist:    allowlist,
		DingTalkBotNames:     botNames,
		DashboardAPIBase:     strings.TrimRight(getVal("DASHBOARD_API_BASE", DefaultDashboardAPIBase), "/"),
		DashboardAPIToken:    apiToken,
		LLMBaseURL:           baseURL,
		LLMAPIKey:            apiKey,
		LLMModel:             model,
		LLMMaxTokens:         maxTokens,
		WebToolsEnabled:      webTools,
		NotionToolsEnabled:   notionTools,
		NotionDatabases:      notionDBs,
		AutoLearn:            autoLearn,
		MaxReplyChars:        maxReply,
		MaxIterations:        maxIter,
		HistoryTTL:           historyTTL,
		DataDir:              getVal("DINGTALK_DATA_DIR", DefaultDataDir),
	}, nil
}
