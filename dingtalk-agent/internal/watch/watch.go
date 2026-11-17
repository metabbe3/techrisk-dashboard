// Package watch holds chat-created incident status watches: a JSON-persisted
// store and the pending-request handoff between the (context-free) LLM tools
// and the bot, which binds each request to the conversation it really came
// from. Mirrors internal/schedule.
package watch

import (
	"encoding/json"
	"fmt"
	"log"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/util"
)

const (
	// MaxPerConversation caps active watches per chat.
	MaxPerConversation = 20
	// pendingTTL drops requests whose turn failed before the bot claimed them.
	pendingTTL = time.Hour
)

// Item is one incident status watch, bound to the conversation it was created in.
type Item struct {
	ID               int       `json:"id"`
	IncidentNo       string    `json:"incident_no"`
	Title            string    `json:"title"`
	LastStatus       string    `json:"last_status"`
	ConversationID   string    `json:"conversation_id"`
	ConversationType string    `json:"conversation_type"` // "1" = DM, "2" = group
	StaffID          string    `json:"staff_id"`          // creator; DM target
	Nick             string    `json:"nick"`
	Created          time.Time `json:"created"`
}

// Describe renders "#3 20250115_IN_1234 'Payment timeout' (status: Open)".
func (it Item) Describe() string {
	title := util.Cut(it.Title, 60)
	if title != it.Title {
		title += "…"
	}
	return fmt.Sprintf("#%d %s %q (status: %s)", it.ID, it.IncidentNo, title, it.LastStatus)
}

// Store persists watches in <dir>/watches.json ("" dir = memory-only).
type Store struct {
	path  string
	mu    sync.Mutex
	items map[int]Item
}

func NewStore(dir string) *Store {
	s := &Store{items: map[int]Item{}}
	if dir == "" {
		return s
	}
	s.path = filepath.Join(dir, "watches.json")
	var list []Item
	if err := util.LoadJSON(s.path, &list); err != nil {
		log.Printf("[watch] loading %s: %v — starting empty", s.path, err)
	}
	for _, it := range list {
		s.items[it.ID] = it
	}
	return s
}

// Add saves a watch for a conversation, returning the stored item.
func (s *Store) Add(it Item) (Item, error) {
	it.IncidentNo = strings.TrimSpace(it.IncidentNo)
	if it.IncidentNo == "" {
		return Item{}, fmt.Errorf("incident number is required")
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.listLocked(it.ConversationID)) >= MaxPerConversation {
		return Item{}, fmt.Errorf("this chat already has %d watches — cancel one first", MaxPerConversation)
	}
	for _, w := range s.listLocked(it.ConversationID) { // one watch per incident per chat
		if w.IncidentNo == it.IncidentNo {
			return Item{}, fmt.Errorf("%s is already watched in this chat (watch #%d)", it.IncidentNo, w.ID)
		}
	}
	for id := range s.items {
		it.ID = max(it.ID, id)
	}
	it.ID++
	if it.Created.IsZero() {
		it.Created = time.Now()
	}
	s.items[it.ID] = it
	return it, s.saveLocked()
}

// Cancel removes a watch — only from the conversation that owns it.
func (s *Store) Cancel(id int, conversationID string) (Item, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	it, ok := s.items[id]
	if !ok || it.ConversationID != conversationID {
		return Item{}, fmt.Errorf("no watch #%d in this chat", id)
	}
	delete(s.items, id)
	return it, s.saveLocked()
}

// List returns a conversation's watches by ID.
func (s *Store) List(conversationID string) []Item {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.listLocked(conversationID)
}

// All returns every watch across conversations (the poller's sweep input).
func (s *Store) All() []Item {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := make([]Item, 0, len(s.items))
	for _, it := range s.items {
		out = append(out, it)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out
}

// SetStatus persists a fired watch's new LastStatus so the next sweep
// doesn't re-fire it. Best-effort logging is the caller's job.
func (s *Store) SetStatus(id int, status string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	it, ok := s.items[id]
	if !ok {
		return fmt.Errorf("no watch #%d", id)
	}
	it.LastStatus = status
	s.items[id] = it
	return s.saveLocked()
}

func (s *Store) listLocked(conversationID string) []Item {
	var out []Item
	for _, it := range s.items {
		if it.ConversationID == conversationID {
			out = append(out, it)
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out
}

func (s *Store) saveLocked() error {
	if s.path == "" {
		return nil
	}
	list := make([]Item, 0, len(s.items))
	for _, it := range s.items {
		list = append(list, it)
	}
	sort.Slice(list, func(i, j int) bool { return list[i].ID < list[j].ID })
	data, err := json.MarshalIndent(list, "", " ")
	if err != nil {
		return err
	}
	return util.AtomicWrite(s.path, data, 0o600)
}

// Request is what an LLM tool call asks for, before it is bound to a chat.
// CancelID > 0 means "cancel that watch"; otherwise it is a new watch.
type Request struct {
	No       string
	CancelID int
}

// Pending parks tool requests under random tokens until the bot claims the
// tokens found in its own turn's tool results (same contract as schedule).
type Pending = util.Pending[Request]

func NewPending() *Pending { return util.NewPending[Request](pendingTTL) }
