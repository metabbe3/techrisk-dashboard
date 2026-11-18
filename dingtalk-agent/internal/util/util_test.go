package util

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestPendingPutTwo(t *testing.T) {
	p := NewPending[string](time.Hour)
	t1 := p.Put("a")
	t2 := p.Put("b")
	for tok, want := range map[string]string{t1: "a", t2: "b"} {
		got, ok := p.Claim(tok)
		if !ok || got != want {
			t.Errorf("claim %q: got %q ok=%v, want %q", tok, got, ok, want)
		}
	}
}

func TestCutRuneSafe(t *testing.T) {
	// Byte slicing would split these multi-byte runes.
	s := "แต่ละบุคคลจะมีทัศนคติ"
	if got := Cut(s, 3); strings.Contains(got, "�") {
		t.Errorf("Cut split a rune: %q", got)
	}
	if Cut(s, 100) != s {
		t.Error("Cut must return s unchanged when short enough (rune-based — bytes can exceed the limit while runes do not)")
	}
	if got := CutEllipsis("abc", 10); got != "abc" {
		t.Errorf("CutEllipsis must not suffix an untouched string: %q", got)
	}
	if got := CutEllipsis("abcdef", 3); got != "abc…" {
		t.Errorf("CutEllipsis suffixes only a real cut: %q", got)
	}
	if got := CutEllipsis(s, 100); got != s {
		// bytes 55 > n would have made a byte-guard variant append "…" to an
		// untouched CJK string (the scheduleapi byte-len guard, D12/D8 shape).
		t.Errorf("CutEllipsis is rune-based: %q", got)
	}
}

func TestLoadJSONCorruptRenamed(t *testing.T) {
	p := filepath.Join(t.TempDir(), "state.json")
	os.WriteFile(p, []byte("{bad"), 0o600)
	var v struct{ A int }
	if err := LoadJSON(p, &v); err == nil {
		t.Fatal("corrupt file must error")
	}
	if _, err := os.Stat(p + ".corrupt"); err != nil {
		t.Errorf("corrupt file not kept: %v", err)
	}
	v2 := struct{ A int }{A: 7}
	if err := LoadJSON(filepath.Join(t.TempDir(), "nope.json"), &v2); err != nil || v2.A != 7 {
		t.Errorf("missing file: err=%v v=%+v", err, v2)
	}
}
