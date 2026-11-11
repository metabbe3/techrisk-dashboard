// Package schedule holds the chat-created reminder grammar: a 5-field cron /
// one-shot "when" spec parser (WIB) and the pending-request handoff between the
// (context-free) LLM tools and the bot, which binds each request to the
// conversation it really came from. Storage and dispatch live in the dashboard
// (AiAgent rows) — the local schedules.json store was retired with unification.
package schedule

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"strconv"
	"strings"
	"sync"
	"time"
)

// WIB is the team's wall clock. ponytail: fixed +7 (Indonesia has no DST);
// make it config when a non-WIB team uses the bot.
var WIB = time.FixedZone("WIB", 7*3600)

const (
	// MinRecurringGap stops a cron from spamming a chat (or burning LLM calls).
	MinRecurringGap = 30 * time.Minute
	// MaxTextChars caps the reminder text / task prompt.
	MaxTextChars = 1000
	// pendingTTL drops requests whose turn failed before the bot claimed them.
	pendingTTL = time.Hour
)

// Mode: "message" posts Text as-is; "task" runs Text through the agent at fire
// time and posts the answer (e.g. "summarize open P1/P2 incidents").
const (
	ModeMessage = "message"
	ModeTask    = "task"
)

// Spec is a parsed "when": either a cron expression or a single instant.
type Spec struct {
	cron *cronExpr
	at   time.Time
}

// Parse accepts:
//   - a 5-field cron "min hour dom month dow" in WIB (e.g. "0 9 * * 1-5")
//   - a one-shot "YYYY-MM-DD HH:MM" in WIB
//   - a relative one-shot "in 30m" / "in 2h" / "in 1h30m"
func Parse(when string, now time.Time) (Spec, error) {
	when = strings.TrimSpace(when)
	if rest, ok := strings.CutPrefix(strings.ToLower(when), "in "); ok {
		d, err := time.ParseDuration(strings.ReplaceAll(rest, " ", ""))
		if err != nil || d < time.Minute {
			return Spec{}, fmt.Errorf("relative time must be like 'in 30m' or 'in 2h' (at least 1 minute), got %q", when)
		}
		return Spec{at: now.Add(d).Truncate(time.Minute)}, nil
	}
	if at, err := time.ParseInLocation("2006-01-02 15:04", when, WIB); err == nil {
		if !at.After(now) {
			return Spec{}, fmt.Errorf("%s WIB is in the past (now %s WIB)", when, now.In(WIB).Format("2006-01-02 15:04"))
		}
		return Spec{at: at}, nil
	}
	c, err := parseCron(when)
	if err != nil {
		return Spec{}, fmt.Errorf("when must be a 5-field cron (e.g. '0 9 * * 1' = Mondays 09:00 WIB), 'YYYY-MM-DD HH:MM', or 'in 30m': %v", err)
	}
	s := Spec{cron: c}
	// Smallest gap across the next runs — catches "*/5 9 * * *" style spam.
	prev, ok := s.Next(now)
	if !ok {
		return Spec{}, fmt.Errorf("cron %q never fires within a year", when)
	}
	for i := 0; i < 10; i++ {
		nxt, ok := s.Next(prev)
		if !ok {
			break
		}
		if nxt.Sub(prev) < MinRecurringGap {
			return Spec{}, fmt.Errorf("recurring reminders must be at least %v apart", MinRecurringGap)
		}
		prev = nxt
	}
	return s, nil
}

// Recurring reports whether the spec is a cron (vs a one-shot instant) — the
// bot sends cron vs a resolved run_at to the dashboard on it.
func (s Spec) Recurring() bool { return s.cron != nil }

// Next returns the first fire time strictly after t (false when none within a year).
func (s Spec) Next(t time.Time) (time.Time, bool) {
	if s.cron == nil {
		return s.at, s.at.After(t)
	}
	m := t.In(WIB).Truncate(time.Minute).Add(time.Minute)
	// ponytail: minute scan, ≤527k cheap checks/year; only runs on create/advance.
	for end := m.AddDate(1, 0, 0); m.Before(end); m = m.Add(time.Minute) {
		if s.cron.matches(m) {
			return m, true
		}
	}
	return time.Time{}, false
}

type cronExpr struct {
	min, hour, dom, month, dow [64]bool
	domStar, dowStar           bool
}

func parseCron(expr string) (*cronExpr, error) {
	f := strings.Fields(expr)
	if len(f) != 5 {
		return nil, fmt.Errorf("need 5 fields, got %d", len(f))
	}
	c := &cronExpr{domStar: f[2] == "*", dowStar: f[4] == "*"}
	for i, spec := range []struct {
		set      *[64]bool
		lo, hi   int
		maxValue int
	}{{&c.min, 0, 59, 59}, {&c.hour, 0, 23, 23}, {&c.dom, 1, 31, 31}, {&c.month, 1, 12, 12}, {&c.dow, 0, 6, 7}} {
		if err := parseField(f[i], spec.lo, spec.maxValue, spec.set); err != nil {
			return nil, fmt.Errorf("field %d (%q): %v", i+1, f[i], err)
		}
	}
	if c.dow[7] { // 7 = Sunday too
		c.dow[0] = true
	}
	return c, nil
}

// parseField fills set from "*", "*/n", "a", "a-b", "a-b/n", and comma lists.
func parseField(field string, lo, hi int, set *[64]bool) error {
	for _, part := range strings.Split(field, ",") {
		step := 1
		if base, s, ok := strings.Cut(part, "/"); ok {
			n, err := strconv.Atoi(s)
			if err != nil || n < 1 {
				return fmt.Errorf("bad step %q", s)
			}
			step, part = n, base
		}
		from, to := lo, hi
		if part != "*" {
			a, b, isRange := strings.Cut(part, "-")
			var err error
			if from, err = strconv.Atoi(a); err != nil {
				return fmt.Errorf("bad value %q", a)
			}
			to = from
			if isRange {
				if to, err = strconv.Atoi(b); err != nil {
					return fmt.Errorf("bad value %q", b)
				}
			}
		}
		if from < lo || to > hi || from > to {
			return fmt.Errorf("%d-%d outside %d-%d", from, to, lo, hi)
		}
		for v := from; v <= to; v += step {
			set[v] = true
		}
	}
	return nil
}

func (c *cronExpr) matches(t time.Time) bool {
	if !c.min[t.Minute()] || !c.hour[t.Hour()] || !c.month[int(t.Month())] {
		return false
	}
	dom, dow := c.dom[t.Day()], c.dow[int(t.Weekday())]
	switch { // standard cron: both restricted = either matches
	case c.domStar && c.dowStar:
		return true
	case c.domStar:
		return dow
	case c.dowStar:
		return dom
	default:
		return dom || dow
	}
}

// Request is what an LLM tool call asks for, before it is bound to a chat.
// CancelID > 0 means "cancel that reminder"; otherwise it is a new reminder.
type Request struct {
	When     string
	Mode     string
	Text     string
	CancelID int
	at       time.Time
}

// Pending parks tool requests under random tokens until the bot claims the
// tokens found in its own turn's tool results. A token only appears in the
// history of the conversation whose turn created it, so concurrent turns
// cannot pick up each other's requests, and a claimed token never re-applies.
type Pending struct {
	mu   sync.Mutex
	reqs map[string]Request
}

func NewPending() *Pending { return &Pending{reqs: map[string]Request{}} }

// Put parks req and returns its token.
func (p *Pending) Put(req Request) string {
	b := make([]byte, 8)
	_, _ = rand.Read(b)
	token := hex.EncodeToString(b)
	req.at = time.Now()
	p.mu.Lock()
	defer p.mu.Unlock()
	for t, r := range p.reqs {
		if time.Since(r.at) > pendingTTL {
			delete(p.reqs, t)
		}
	}
	p.reqs[token] = req
	return token
}

// Claim removes and returns the request for token.
func (p *Pending) Claim(token string) (Request, bool) {
	p.mu.Lock()
	defer p.mu.Unlock()
	r, ok := p.reqs[token]
	delete(p.reqs, token)
	return r, ok
}
