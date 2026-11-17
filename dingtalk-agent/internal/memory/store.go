// Package memory persists the bot's user registry and conversation history to
// disk so restarts don't forget who chatted and what was said (auto save +
// auto recall). One JSON file each, atomic rewrite via tmp+rename.
package memory

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/util"
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
	if _, err = f.Write(append(data, '\n')); err != nil {
		return err
	}
	s.knowledge = append(s.knowledge, e)
	// No in-memory trim: the digest covered cursor is an ABSOLUTE index into
	// this slice — trimming the window desyncs it (covered >= len(window))
	// and the incremental merge silently freezes until restart. The prompt
	// path caps itself (Knowledge() keeps the last MaxKnowledgeEntries);
	// the full list here must mirror knowledge.jsonl exactly.
	return nil
}

// Knowledge returns the most recent document summaries (auto recall) — from
// the in-memory mirror; the JSONL used to be re-parsed under the lock on
// every turn.
func (s *Store) Knowledge() []KnowledgeEntry {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.knowledge) > MaxKnowledgeEntries {
		return append([]KnowledgeEntry{}, s.knowledge[len(s.knowledge)-MaxKnowledgeEntries:]...)
	}
	return append([]KnowledgeEntry{}, s.knowledge...)
}

// Digest caps: the compiled knowledge.md digest is injected EVERY turn, so it
// stays bounded however long knowledge.jsonl grows (LLM-Wiki-style compiled
// memory; the raw JSONL layer stays append-only on disk).
const (
	MaxDigestLines   = 40
	MaxDigestLineLen = 200
	digestFile       = "knowledge.md"
	digestMetaFile   = "knowledge_digest.json"
)

type digestMeta struct {
	Covered int `json:"covered"` // knowledge.jsonl entries already merged into the digest
}

// AllKnowledge returns every parsed knowledge entry — the digest backfill's
// view of the raw layer (Knowledge() only serves the last-8 prompt slice).
func (s *Store) AllKnowledge() []KnowledgeEntry {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]KnowledgeEntry{}, s.knowledge...)
}

// KnowledgeDigest returns the compiled digest ("" when none yet).
func (s *Store) KnowledgeDigest() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.digest
}

// DigestCovered returns how many knowledge.jsonl entries the digest already
// covers (the incremental merge's cursor).
func (s *Store) DigestCovered() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.digestCovered
}

// KnowledgeBeyondCovered returns the raw entries the digest does not cover
// yet — knowledgePrompt injects this tail under the digest so a failed merge
// never hides newly-learned documents.
func (s *Store) KnowledgeBeyondCovered() []KnowledgeEntry {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.digestCovered >= len(s.knowledge) {
		return nil
	}
	return append([]KnowledgeEntry{}, s.knowledge[s.digestCovered:]...)
}

// SaveKnowledgeDigest atomically rewrites knowledge.md and advances the
// covered cursor. Caps and normalizes like LearnProfile: bullets, length-capped
// lines, newest MaxDigestLines kept. An empty digest is REFUSED (an empty or
// broken LLM merge must never wipe compiled memory) with the cursor left
// alone — the next merge retries. Digest file first, meta second: a crash
// between them leaves the cursor behind, so entries re-merge (duplicates
// collapse) rather than being lost.
func (s *Store) SaveKnowledgeDigest(text string, covered int) error {
	lines := make([]string, 0, MaxDigestLines)
	for _, ln := range strings.Split(text, "\n") {
		ln = strings.TrimSpace(ln)
		ln = strings.TrimPrefix(ln, "-")
		ln = strings.TrimSpace(ln)
		ln = strings.Trim(ln, `-"* `)
		if ln == "" {
			continue
		}
		lines = append(lines, "- "+util.Cut(ln, MaxDigestLineLen))
	}
	if len(lines) == 0 {
		return errors.New("empty digest refused — never wipe compiled memory")
	}
	if len(lines) > MaxDigestLines {
		lines = lines[len(lines)-MaxDigestLines:] // keep the newest
	}

	s.mu.Lock()
	if err := util.AtomicWrite(filepath.Join(s.dir, digestFile), []byte(strings.Join(lines, "\n")+"\n"), 0o600); err != nil {
		s.mu.Unlock()
		return err
	}
	s.digest = strings.Join(lines, "\n")
	s.digestCovered = covered
	s.mu.Unlock()
	return s.writeJSON(filepath.Join(s.dir, digestMetaFile), digestMeta{Covered: covered})
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
	knownUsers    map[string]bool  // staff ids already in users.jsonl (dedupe per person, S9)
	knowledge     []KnowledgeEntry // parsed mirror of knowledge.jsonl (was re-read every turn)
	digest        string           // compiled knowledge.md digest ("" = none yet)
	digestCovered int              // knowledge.jsonl entries already merged into the digest

	historyTTL time.Duration // 0 = no load-time TTL filtering

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
		knownUsers:  map[string]bool{},
	}
	s.loadUsers()
	s.loadKnowledge()
	s.loadHistory()
	s.loadProfiles()
	s.loadDigest()
	return s
}

// AppendUser records a chat contact (auto save who chats). One line per
// person: the file used to grow a line per message, forever.
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
	if s.knownUsers[rec.StaffID] { // first contact wins; later records are noise
		return nil
	}
	f, err := os.OpenFile(s.path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = f.Write(append(data, '\n'))
	if err != nil {
		return err
	}
	s.knownUsers[rec.StaffID] = true
	return nil
}

// loadUsers seeds the dedupe set from the existing registry (a restart must
// not re-append every person already on disk).
func (s *Store) loadUsers() {
	data, err := os.ReadFile(s.path)
	if err != nil {
		return
	}
	for _, line := range strings.Split(string(data), "\n") {
		var r UserRecord
		if json.Unmarshal([]byte(line), &r) == nil && r.StaffID != "" {
			s.knownUsers[r.StaffID] = true
		}
	}
}

// loadKnowledge parses knowledge.jsonl once; Knowledge() served from memory.
func (s *Store) loadKnowledge() {
	data, err := os.ReadFile(filepath.Join(s.dir, "knowledge.jsonl"))
	if err != nil {
		return
	}
	for _, line := range strings.Split(string(data), "\n") {
		var e KnowledgeEntry
		if json.Unmarshal([]byte(line), &e) == nil && e.File != "" {
			s.knowledge = append(s.knowledge, e)
		}
	}
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
// the file stays bounded on long-lived servers. Synchronous write (audit
// 2026-11-12 considered a debounced async flusher — rejected: restart
// recall is a hard contract and a deferred write widens the crash-loss
// window; at this agent's scale the whole-map rewrite is cheap and atomic).
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
	convs := make(map[string]HistoryEntry, len(s.history))
	for id, e := range s.history {
		convs[id] = e
	}
	s.hmu.Unlock()
	return s.writeJSON(s.historyPath, historyFile{Conversations: convs})
}

func (s *Store) loadHistory() {
	var hf historyFile
	if err := util.LoadJSON(s.historyPath, &hf); err != nil || hf.Conversations == nil {
		return // no file yet (nil), or corrupt already renamed away
	}
	// Hydration used to reload EVERY conversation, including ones whose TTL
	// had expired while the bot was down — stale context came back until the
	// next save purged it (audit round 4).
	if s.historyTTL > 0 {
		for id, e := range hf.Conversations {
			if time.Since(e.LastSeen) > s.historyTTL {
				delete(hf.Conversations, id)
			}
		}
	}
	s.history = hf.Conversations
}

// SetHistoryTTL arms the load-time TTL filter; call before first read.
func (s *Store) SetHistoryTTL(ttl time.Duration) { s.historyTTL = ttl }

func (s *Store) writeJSON(path string, v any) error {
	data, err := json.Marshal(v)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	return util.AtomicWrite(path, data, 0o600)
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
		ln = util.Cut(ln, MaxProfileLineLen)
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
	// Snapshot a COPY under the lock — handing the live map to json.Marshal
	// outside it raced a concurrent learnProfile write ("concurrent map
	// iteration and map write", fatal, not recoverable).
	profiles := make(map[string]UserProfile, len(s.profiles))
	for id, p := range s.profiles {
		profiles[id] = p
	}
	s.pmu.Unlock()
	return s.writeJSON(filepath.Join(s.dir, profileFile), profileFileFormat{Profiles: profiles})
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
	var pf profileFileFormat
	if err := util.LoadJSON(filepath.Join(s.dir, profileFile), &pf); err != nil || pf.Profiles == nil {
		return
	}
	s.profiles = pf.Profiles
}

// loadDigest seeds the compiled digest + covered cursor from disk (a restart
// must not re-merge everything; "" = the boot backfill fills it).
func (s *Store) loadDigest() {
	var meta digestMeta
	if util.LoadJSON(filepath.Join(s.dir, digestMetaFile), &meta) == nil {
		s.digestCovered = meta.Covered
	}
	if data, err := os.ReadFile(filepath.Join(s.dir, digestFile)); err == nil {
		s.digest = strings.TrimSpace(string(data))
	}
}
