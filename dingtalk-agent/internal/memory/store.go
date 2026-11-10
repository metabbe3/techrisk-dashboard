// Package memory persists the bot's user registry and conversation history to
// disk so restarts don't forget who chatted and what was said (auto save +
// auto recall). One JSON file each, atomic rewrite via tmp+rename.
package memory

import (
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// UserRecord is one line of the append-only user registry (JSONL).
type UserRecord struct {
	Time             time.Time `json:"time"`
	StaffID          string    `json:"staff_id"`
	Nick             string    `json:"nick"`
	ConversationID   string    `json:"conversation_id"`
	ConversationType string    `json:"conversation_type"` // "1" DM, "2" group
}

// KnowledgeEntry is a summarized document the agent should remember.
type KnowledgeEntry struct {
	Time    time.Time `json:"time"`
	File    string    `json:"file"`
	Author  string    `json:"author"` // sender nick
	Summary string    `json:"summary"`
}

// MaxKnowledgeEntries bounds how many document summaries are injected into prompts.
const MaxKnowledgeEntries = 8

// AppendKnowledge records a document summary (auto save, knowledge updated).
func (s *Store) AppendKnowledge(e KnowledgeEntry) error {
	if e.Time.IsZero() {
		e.Time = time.Now()
	}
	data, err := json.Marshal(e)
	if err != nil {
		return err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	f, err := os.OpenFile(filepath.Join(s.dir, "knowledge.jsonl"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = f.Write(append(data, '\n'))
	return err
}

// Knowledge returns the most recent document summaries (auto recall).
func (s *Store) Knowledge() []KnowledgeEntry {
	s.mu.Lock()
	defer s.mu.Unlock()
	data, err := os.ReadFile(filepath.Join(s.dir, "knowledge.jsonl"))
	if err != nil {
		return nil
	}
	var recs []KnowledgeEntry
	for _, line := range strings.Split(string(data), "\n") {
		if line == "" {
			continue
		}
		var e KnowledgeEntry
		if json.Unmarshal([]byte(line), &e) == nil {
			recs = append(recs, e)
		}
	}
	if len(recs) > MaxKnowledgeEntries {
		recs = recs[len(recs)-MaxKnowledgeEntries:]
	}
	return recs
}

// historyFile mirrors the bot's in-memory history map.
type historyFile struct {
	Conversations map[string]HistoryEntry `json:"conversations"`
}

// HistoryEntry is one persisted conversation.
type HistoryEntry struct {
	Messages []llm.ChatMessage `json:"messages"`
	LastSeen time.Time         `json:"last_seen"`
}

type Store struct {
	dir string

	mu       sync.Mutex
	path     string // users JSONL
	pmu      sync.Mutex
	profiles map[string]UserProfile

	hmu         sync.Mutex
	history     map[string]HistoryEntry
	historyPath string
}

// NewStore loads (auto recall) or initializes the store inside dir.
func NewStore(dir string) *Store {
	_ = os.MkdirAll(dir, 0o700)
	s := &Store{
		dir:         dir,
		path:        filepath.Join(dir, "users.jsonl"),
		historyPath: filepath.Join(dir, "history.json"),
		history:     map[string]HistoryEntry{},
		profiles:    map[string]UserProfile{},
	}
	s.loadHistory()
	s.loadProfiles()
	return s
}

// AppendUser records a chat contact (auto save who chats).
func (s *Store) AppendUser(rec UserRecord) error {
	if rec.Time.IsZero() {
		rec.Time = time.Now()
	}
	data, err := json.Marshal(rec)
	if err != nil {
		return err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	f, err := os.OpenFile(s.path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = f.Write(append(data, '\n'))
	return err
}

// Users returns every recorded contact in chronological order.
func (s *Store) Users() ([]UserRecord, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	data, err := os.ReadFile(s.path)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var recs []UserRecord
	for _, line := range strings.Split(string(data), "\n") {
		if line == "" {
			continue
		}
		var r UserRecord
		if json.Unmarshal([]byte(line), &r) == nil {
			recs = append(recs, r)
		}
	}
	return recs, nil
}

// TakeHistory returns the persisted messages for a conversation (may be nil).
func (s *Store) TakeHistory(conversationID string) []llm.ChatMessage {
	s.hmu.Lock()
	defer s.hmu.Unlock()
	entry, ok := s.history[conversationID]
	if !ok {
		return nil
	}
	return entry.Messages
}

// All returns every persisted conversation (boot-time hydration for the bot).
func (s *Store) All() map[string]HistoryEntry {
	s.hmu.Lock()
	defer s.hmu.Unlock()
	out := make(map[string]HistoryEntry, len(s.history))
	for id, e := range s.history {
		out[id] = e
	}
	return out
}

// SaveHistory persists a conversation, dropping entries idle beyond ttl so
// the file stays bounded on long-lived servers.
func (s *Store) SaveHistory(conversationID string, messages []llm.ChatMessage, ttl time.Duration) error {
	s.hmu.Lock()
	s.history[conversationID] = HistoryEntry{Messages: messages, LastSeen: time.Now()}
	if ttl > 0 {
		for id, e := range s.history {
			if time.Since(e.LastSeen) > ttl {
				delete(s.history, id)
			}
		}
	}
	snap := historyFile{Conversations: s.history}
	s.hmu.Unlock()
	return s.writeJSON(s.historyPath, snap)
}

func (s *Store) loadHistory() {
	data, err := os.ReadFile(s.historyPath)
	if err != nil {
		return // no file yet — start fresh
	}
	var hf historyFile
	if json.Unmarshal(data, &hf) == nil && hf.Conversations != nil {
		s.history = hf.Conversations
	}
}

// writeJSON atomically replaces path (tmp+rename).
func (s *Store) writeJSON(path string, v any) error {
	data, err := json.Marshal(v)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

// UserProfile is what the agent has learned about one person's preferences
// (language, reply format, standing instructions) — injected into prompts for
// that sender only.
type UserProfile struct {
	Nick      string    `json:"nick"`
	UpdatedAt time.Time `json:"updated_at"`
	Lines     []string  `json:"lines"`
}

// Profile caps: bounded prompt cost per user, however long they've chatted.
const (
	MaxProfileLines   = 8
	MaxProfileLineLen = 150
	profileFile       = "profiles.json"
)

type profileFileFormat struct {
	Profiles map[string]UserProfile `json:"profiles"`
}

// LearnProfile replaces a user's learned profile, capping line count/length.
func (s *Store) LearnProfile(staffID, nick string, lines []string) error {
	clean := make([]string, 0, MaxProfileLines)
	for _, ln := range lines {
		ln = strings.TrimSpace(ln)
		ln = strings.Trim(ln, `-"* `)
		if ln == "" {
			continue
		}
		if len(ln) > MaxProfileLineLen {
			ln = ln[:MaxProfileLineLen]
		}
		clean = append(clean, ln)
		if len(clean) == MaxProfileLines {
			break
		}
	}
	if len(clean) == 0 {
		return nil // never wipe a profile with an empty extraction
	}

	s.pmu.Lock()
	s.profiles[staffID] = UserProfile{Nick: nick, UpdatedAt: time.Now(), Lines: clean}
	snap := profileFileFormat{Profiles: s.profiles}
	s.pmu.Unlock()
	return s.writeJSON(filepath.Join(s.dir, profileFile), snap)
}

// Profile returns a user's learned preference lines (nil when unknown).
func (s *Store) Profile(staffID string) []string {
	s.pmu.Lock()
	defer s.pmu.Unlock()
	if p, ok := s.profiles[staffID]; ok {
		return p.Lines
	}
	return nil
}

func (s *Store) loadProfiles() {
	data, err := os.ReadFile(filepath.Join(s.dir, profileFile))
	if err != nil {
		return // no file yet — start fresh
	}
	var pf profileFileFormat
	if json.Unmarshal(data, &pf) == nil && pf.Profiles != nil {
		s.profiles = pf.Profiles
	}
}
