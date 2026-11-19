package guardrail

import (
	"strings"
	"testing"
)

// BenchmarkFilterReplyData exercises the extraction-payload path at the
// dashboard's 60000-char cap: prose + every redaction class + JSON rows.
func BenchmarkFilterReplyData(b *testing.B) {
	payload := strings.Join([]string{
		"Investigation notes for timeout to database.",
		"api_key = sk-live-AbCdEf123456789012345",
		"github_pat_11AAAAAAA0123456789012345678901234",
		"AKIAIOSFODNN7EXAMPLE",
		"Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c",
		"mysql://root:password@db:3306/laravel",
		"-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
		"```tool_call\n{\"name\":\"search_incidents\"}\n```",
		strings.Repeat(`{"title":"timeout to database","status":"Open","sev":"P2"},`, 1000),
	}, "\n")
	for b.Loop() {
		FilterReplyData(payload, 60000)
	}
}
