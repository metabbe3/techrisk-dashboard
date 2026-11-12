package guardrail

import (
	"regexp"
	"strings"
	"unicode/utf8"
)

const (
	MaxReplyLength   = 8000
	TruncationFooter = "\n\n_(truncated — type \"more\")_"
)

var (
	// Markup & reasoning patterns to strip
	reHTMLComment    = regexp.MustCompile(`(?s)<!--.*?-->`)
	reSTags          = regexp.MustCompile(`(?s)<s>.*?</s>|<s\s*/?>|</s>`)
	reSystemLine     = regexp.MustCompile(`(?m)^system:.*$\r?\n?`)
	reBlank3         = regexp.MustCompile(`\n{3,}`)
	reToolCallFence  = regexp.MustCompile("(?s)```(?:json|tool_call|function_call)?\\s*\\{[^`]*?\"(?:name|tool_calls|function)\"[^`]*?\\}\\s*```")
	reGenericFenceTC = regexp.MustCompile("(?s)```(?:tool_call|function_call)\\b.*?```")

	// Markers of system prompt leakage
	// Case-SENSITIVE: a lowercase match turned every "peraturan OJK" line
	// into a "[System instruction omitted]" redaction. These markers are
	// only ever quoted in the system prompt's exact casing.
	systemLeakMarkers = []string{
		"You are GoAgent",
		"You are Risk Agent",
		"ATURAN",
		"system prompt",
	}
)

// FilterReplyCap applies post-processing to a reply before it reaches the
// user:
// 1. Redacts sensitive secrets, keys, and tokens.
// 2. Strips internal reasoning markup, HTML comments, <s> tags, and raw tool call fences.
// 3. Sanitizes accidental quotations of system instructions.
// 4. Caps reply length to maxLen with a friendly truncation footer.
func FilterReplyCap(reply string, maxLen int) string {
	if maxLen <= 0 {
		maxLen = MaxReplyLength
	}

	// Step 0: Hard scope gate — identity lock, prompt-leak lock, off-topic
	// delivery lock. A firing gate replaces the ENTIRE reply.
	if guarded := HardGuardReply(reply); guarded != "" {
		reply = guarded
	}

	// Step 1: Secret redaction
	redacted, _ := Redact(reply)

	// Step 2: Strip internal reasoning markup & fences
	filtered := reHTMLComment.ReplaceAllString(redacted, "")
	filtered = reSTags.ReplaceAllString(filtered, "")
	filtered = reToolCallFence.ReplaceAllString(filtered, "")
	filtered = reGenericFenceTC.ReplaceAllString(filtered, "")
	filtered = reSystemLine.ReplaceAllString(filtered, "")

	// Step 3: Sanitize system instruction leaks
	filtered = sanitizeSystemLeaks(filtered)

	// Clean up extra blank lines
	filtered = reBlank3.ReplaceAllString(filtered, "\n\n")
	filtered = strings.TrimSpace(filtered)

	// Step 4: Cap length to maxLen
	if len(filtered) > maxLen {
		cutIndex := maxLen - len(TruncationFooter)
		if cutIndex > 0 {
			// Find nearest newline or space before cutIndex if possible
			lastNL := strings.LastIndex(filtered[:cutIndex], "\n")
			if lastNL > cutIndex-200 && lastNL > 0 {
				cutIndex = lastNL
			}
			// Never split a multi-byte rune at the cut.
			for cutIndex > 0 && !utf8.RuneStart(filtered[cutIndex]) {
				cutIndex--
			}
			filtered = strings.TrimRight(filtered[:cutIndex], "\r\n ") + TruncationFooter
		} else {
			filtered = filtered[:maxLen]
			for maxLen > 0 && !utf8.RuneStart(filtered[maxLen]) {
				maxLen--
			}
			filtered = filtered[:maxLen]
		}
	}

	return filtered
}

// sanitizeSystemLeaks replaces lines or sentences containing system prompt markers with a safe placeholder.
func sanitizeSystemLeaks(text string) string {
	lines := strings.Split(text, "\n")
	for i, line := range lines {
		for _, marker := range systemLeakMarkers {
			if strings.Contains(line, marker) {
				lines[i] = "[System instruction omitted]"
				break
			}
		}
	}
	return strings.Join(lines, "\n")
}
