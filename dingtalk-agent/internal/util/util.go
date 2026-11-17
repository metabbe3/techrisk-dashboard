// Package util holds the helpers shared across the agent's packages: the
// generic pending-request handoff between LLM tools and the bot, rune-safe
// truncation, and crash-safe state-file IO.
package util

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"log"
	"os"
	"strings"
	"sync"
	"time"
)

// Pending parks tool requests under random tokens until the bot claims the
// tokens found in its own turn's tool results. A token only appears in the
// history of the conversation whose turn created it, so concurrent turns
// cannot pick up each other's requests, and a claimed token never re-applies.
type Pending[T any] struct {
	mu   sync.Mutex
	reqs map[string]pendingEntry[T]
	ttl  time.Duration
}

type pendingEntry[T any] struct {
	at  time.Time
	req T
}

// NewPending builds a Pending whose entries expire after ttl (a turn that
// failed before the bot claimed its tokens).
func NewPending[T any](ttl time.Duration) *Pending[T] {
	return &Pending[T]{reqs: map[string]pendingEntry[T]{}, ttl: ttl}
}

// Put parks req and returns its token — 16 hex chars, the format the
// ScheduleTicket/WatchTicket regexes publish in the tool descriptions.
func (p *Pending[T]) Put(req T) string {
	var b [8]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic("crypto/rand unavailable: " + err.Error())
	}
	token := hex.EncodeToString(b[:])
	p.mu.Lock()
	defer p.mu.Unlock()
	now := time.Now()
	for t, e := range p.reqs {
		if now.Sub(e.at) > p.ttl {
			delete(p.reqs, t)
		}
	}
	p.reqs[token] = pendingEntry[T]{at: now, req: req}
	return token
}

// Claim removes and returns the request for token.
func (p *Pending[T]) Claim(token string) (T, bool) {
	p.mu.Lock()
	defer p.mu.Unlock()
	e, ok := p.reqs[token]
	delete(p.reqs, token)
	return e.req, ok
}

// Cut truncates s to at most n runes (byte slicing splits CJK/emoji).
func Cut(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n])
}

// AtomicWrite writes data to path via temp file + rename, so a crash mid-write
// never leaves a truncated state file.
func AtomicWrite(path string, data []byte, perm os.FileMode) error {
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, perm); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

// ReadJSONL parses path as one JSON value per line, skipping blank lines.
func ReadJSONL[T any](path string) ([]T, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, nil
		}
		return nil, err
	}
	var out []T
	for _, ln := range strings.Split(string(data), "\n") {
		ln = strings.TrimSpace(ln)
		if ln == "" {
			continue
		}
		var v T
		if err := json.Unmarshal([]byte(ln), &v); err != nil {
			return nil, err
		}
		out = append(out, v)
	}
	return out, nil
}

// LoadJSON unmarshals path into v; a missing file leaves v zero and returns
// nil. A file that does not parse is renamed to *.corrupt (kept for
// forensics) and the error returned, so callers log and start fresh instead
// of silently overwriting their state with an empty file.
func LoadJSON(path string, v any) error {
	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	if err := json.Unmarshal(data, v); err != nil {
		if rerr := os.Rename(path, path+".corrupt"); rerr == nil {
			log.Printf("[util] %s did not parse (%v) — moved to %s.corrupt, starting fresh", path, err, path)
		}
		return err
	}
	return nil
}
