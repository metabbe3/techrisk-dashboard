package guardrail

import (
	"regexp"
	"strings"
)

const (
	MaxReplyLength   = 3800
	TruncationFooter = "\n\n_(truncated — type \"more\")_"
)

var (
	// Markup & reasoning patterns to strip
	reHTMLComment    = regexp.MustCompile(`(?s)<!--.*?-->`)
	reSTags          = regexp.MustCompile(`(?s)<s>.*?</s>|<s\s*/?>|</s>`)
	reSystemLine     = regexp.MustCompile(`(?m)^system:.*$\r?\n?`)
	reToolCallFence  = regexp.MustCompile("(?s)```(?:json|tool_call|function_call)?\\s*\\{[^`]*?\"(?:name|tool_calls|function)\"[^`]*?\\}\\s*```")
	reGenericFenceTC = regexp.MustCompile("(?s)```(?:tool_call|function_call)\\b.*?```")

	// Markers of system prompt leakage
	systemLeakMarkers = []string{
		"You are GoAgent",
		"You are Risk Agent",
		"ATURAN",
		"system prompt",
	}
)

// FilterReply applies post-processing to assistant replies before transmitting them to Telegram:
// 1. Redacts sensitive secrets, keys, and tokens.
// 2. Strips internal reasoning markup, HTML comments, <s> tags, and raw tool call fences.
// 3. Sanitizes accidental quotations of system instructions.
// 4. Caps reply length to MaxReplyLength (3800 chars) with a friendly truncation footer.
func FilterReply(reply string) string {
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
	for strings.Contains(filtered, "\n\n\n") {
		filtered = strings.ReplaceAll(filtered, "\n\n\n", "\n\n")
	}
	filtered = strings.TrimSpace(filtered)

	// Step 4: Cap length to MaxReplyLength
	if len(filtered) > MaxReplyLength {
		cutIndex := MaxReplyLength - len(TruncationFooter)
		if cutIndex > 0 {
			// Find nearest newline or space before cutIndex if possible
			lastNL := strings.LastIndex(filtered[:cutIndex], "\n")
			if lastNL > cutIndex-200 && lastNL > 0 {
				cutIndex = lastNL
			}
			filtered = strings.TrimRight(filtered[:cutIndex], "\r\n ") + TruncationFooter
		} else {
			filtered = filtered[:MaxReplyLength]
		}
	}

	return filtered
}

// sanitizeSystemLeaks replaces lines or sentences containing system prompt markers with a safe placeholder.
func sanitizeSystemLeaks(text string) string {
	lines := strings.Split(text, "\n")
	for i, line := range lines {
		lowerLine := strings.ToLower(line)
		hasLeak := false
		for _, marker := range systemLeakMarkers {
			if strings.Contains(line, marker) || strings.Contains(lowerLine, strings.ToLower(marker)) {
				hasLeak = true
				break
			}
		}
		if hasLeak {
			lines[i] = "[System instruction omitted]"
		}
	}
	return strings.Join(lines, "\n")
}
