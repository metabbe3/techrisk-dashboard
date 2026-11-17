package schedule

import (
	"strings"
	"testing"
	"time"
)

// Wed 2026-11-11 10:00 WIB
var now = time.Date(2026, 11, 11, 10, 0, 0, 0, WIB)

func next(t *testing.T, when string) time.Time {
	t.Helper()
	s, err := Parse(when, now)
	if err != nil {
		t.Fatalf("Parse(%q): %v", when, err)
	}
	n, ok := s.Next(now)
	if !ok {
		t.Fatalf("Parse(%q) never fires", when)
	}
	return n
}

func TestParseNext(t *testing.T) {
	cases := map[string]string{
		"0 9 * * 1":        "2026-11-16 09:00", // next Monday
		"30 10 * * *":      "2026-11-11 10:30", // later today
		"0 9 * * 1-5":      "2026-11-12 09:00", // weekdays → Thursday
		"0 8 1 * *":        "2026-12-01 08:00", // 1st of month
		"0 9 * * 0":        "2026-11-15 09:00", // Sunday as 0
		"0 9 * * 7":        "2026-11-15 09:00", // Sunday as 7
		"0 9,15 * * *":     "2026-11-11 15:00", // list
		"0 */6 * * *":      "2026-11-11 12:00", // step
		"2026-11-12 08:15": "2026-11-12 08:15", // one-shot
		"in 30m":           "2026-11-11 10:30", // relative
		"in 2h":            "2026-11-11 12:00",
	}
	for when, want := range cases {
		if got := next(t, when).In(WIB).Format("2006-01-02 15:04"); got != want {
			t.Errorf("%q: next = %s, want %s", when, got, want)
		}
	}
}

func TestParseRejects(t *testing.T) {
	for when, wantErr := range map[string]string{
		"*/5 * * * *":      "30m0s apart", // spam
		"0,10 9 * * *":     "30m0s apart", // spam hidden in a list
		"* * * *":          "5-field",
		"0 25 * * *":       "outside",
		"2026-11-10 09:00": "in the past",
		"in 10s":           "at least 1 minute",
		"every monday":     "5-field",
	} {
		_, err := Parse(when, now)
		if err == nil || !strings.Contains(err.Error(), wantErr) {
			t.Errorf("Parse(%q) err = %v, want containing %q", when, err, wantErr)
		}
	}
}

// Recurring decides whether bindSchedule sends cron or a resolved run_at to the dashboard.
func TestSpecRecurring(t *testing.T) {
	if s, err := Parse("0 9 * * 1", now); err != nil || !s.Recurring() {
		t.Errorf("cron recurring = %v (err %v)", s.Recurring(), err)
	}
	if s, err := Parse("in 30m", now); err != nil || s.Recurring() {
		t.Errorf("one-shot recurring = %v (err %v)", s.Recurring(), err)
	}
}

func TestPendingClaimOnce(t *testing.T) {
	p := NewPending()
	tok := p.Put(Request{When: "in 1h", Text: "x"})
	if _, ok := p.Claim(tok); !ok {
		t.Fatal("claim failed")
	}
	if _, ok := p.Claim(tok); ok {
		t.Error("a token must only be claimable once")
	}
}
