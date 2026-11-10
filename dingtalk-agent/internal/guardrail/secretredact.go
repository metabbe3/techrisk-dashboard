package guardrail

import (
	"fmt"
	"os"
	"regexp"
	"strconv"
	"strings"
	"unicode"
)

var (
	// Common API Keys
	reOpenAIKey = regexp.MustCompile(`\bsk-[A-Za-z0-9_-]{16,}\b`)
	reGitHubKey = regexp.MustCompile(`\b(?:ghp|gho|github_pat)_[A-Za-z0-9_]{16,}\b`)
	reAWSKey    = regexp.MustCompile(`\bAKIA[0-9A-Z]{16}\b`)
	reSlackKey  = regexp.MustCompile(`\bxox[bap]-[A-Za-z0-9_-]{10,}\b`)
	reGoogleKey = regexp.MustCompile(`\bAIza[0-9A-Za-z_-]{30,}\b`)

	// Bearer Token & JWT
	reBearer = regexp.MustCompile(`(?i)\b(Bearer\s+)([A-Za-z0-9._-]{20,})\b`)
	reJWT    = regexp.MustCompile(`\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}(?:\.[A-Za-z0-9_-]+)?\b`)

	// Database Connection Strings with Password
	reConnString = regexp.MustCompile(`((?:postgres|mysql|mongodb(?:\+srv)?|redis|amqp)://[^:\s/@]+:)([^@\s/]+)(@)`)

	// Private Key Block
	rePrivateKey = regexp.MustCompile(`-----BEGIN (?:[A-Z0-9 ]+)?PRIVATE KEY-----[\s\S]*?-----END (?:[A-Z0-9 ]+)?PRIVATE KEY-----`)

	// Key-Value Credentials (password=, passwd=, secret=, token=, api_key=, apikey=)
	reKeyValueCred = regexp.MustCompile(`(?i)\b(password|passwd|secret|token|api_key|apikey)\s*=\s*(["']?)([^\s;&"'\r\n]+)(["']?)`)

	// Email
	reEmail = regexp.MustCompile(`\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b`)

	// Credit Card Candidates (16 digits with optional space/hyphen)
	reCardCandidate = regexp.MustCompile(`\b(?:\d{4}[ -]?){3}\d{4}\b`)

	// IPv4 Pattern
	reIPv4 = regexp.MustCompile(`\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b`)
)

// Redact scans the text and replaces sensitive credentials, keys, and tokens with [REDACTED:<type>].
// It returns the redacted text and a list of hit types (without the secret values).
func Redact(text string) (string, []string) {
	var hits []string
	hitMap := make(map[string]bool)

	addHit := func(hitType string) {
		if !hitMap[hitType] {
			hitMap[hitType] = true
			hits = append(hits, hitType)
		}
	}

	result := text

	// 1. Private key blocks (entire block)
	if rePrivateKey.MatchString(result) {
		result = rePrivateKey.ReplaceAllStringFunc(result, func(s string) string {
			addHit("private_key")
			return "[REDACTED:private_key]"
		})
	}

	// 2. Database Connection Strings: redact password portion
	if reConnString.MatchString(result) {
		result = reConnString.ReplaceAllStringFunc(result, func(s string) string {
			m := reConnString.FindStringSubmatch(s)
			if len(m) == 4 {
				addHit("password")
				return m[1] + "[REDACTED:password]" + m[3]
			}
			return s
		})
	}

	// 3. Bearer Header (redact token part)
	if reBearer.MatchString(result) {
		result = reBearer.ReplaceAllStringFunc(result, func(s string) string {
			m := reBearer.FindStringSubmatch(s)
			if len(m) == 3 {
				addHit("token")
				return m[1] + "[REDACTED:token]"
			}
			return s
		})
	}

	// 4. JWT tokens
	if reJWT.MatchString(result) {
		result = reJWT.ReplaceAllStringFunc(result, func(s string) string {
			addHit("jwt")
			return "[REDACTED:jwt]"
		})
	}

	// 5. Common API Keys
	apiKeys := []struct {
		re      *regexp.Regexp
		hitType string
	}{
		{reOpenAIKey, "api_key"},
		{reGitHubKey, "api_key"},
		{reAWSKey, "api_key"},
		{reSlackKey, "api_key"},
		{reGoogleKey, "api_key"},
	}
	for _, ak := range apiKeys {
		if ak.re.MatchString(result) {
			result = ak.re.ReplaceAllStringFunc(result, func(s string) string {
				addHit(ak.hitType)
				return "[REDACTED:api_key]"
			})
		}
	}

	// 6. Key-Value Credentials (password=, passwd=, secret=, token=, api_key=, apikey=)
	if reKeyValueCred.MatchString(result) {
		result = reKeyValueCred.ReplaceAllStringFunc(result, func(s string) string {
			m := reKeyValueCred.FindStringSubmatch(s)
			if len(m) == 5 {
				keyName := strings.ToLower(m[1])
				val := m[3]
				if len(val) > 3 {
					tag := "secret"
					switch keyName {
					case "password", "passwd":
						tag = "password"
					case "token":
						tag = "token"
					case "api_key", "apikey":
						tag = "api_key"
					}
					addHit(tag)
					return fmt.Sprintf("%s=%s[REDACTED:%s]%s", m[1], m[2], tag, m[4])
				}
			}
			return s
		})
	}

	// 7. Credit card numbers (validated with Luhn algorithm)
	if reCardCandidate.MatchString(result) {
		result = reCardCandidate.ReplaceAllStringFunc(result, func(s string) string {
			if isValidLuhn(s) {
				addHit("card")
				return "[REDACTED:card]"
			}
			return s
		})
	}

	// 8. Email addresses
	if reEmail.MatchString(result) {
		result = reEmail.ReplaceAllStringFunc(result, func(s string) string {
			addHit("email")
			return "[REDACTED:email]"
		})
	}

	// 9. IPv4 Masking (only if GOAGENT_MASK_IP is true or 1)
	if isMaskIPEnabled() {
		result = reIPv4.ReplaceAllStringFunc(result, func(s string) string {
			m := reIPv4.FindStringSubmatch(s)
			if len(m) == 5 {
				o1, err1 := strconv.Atoi(m[1])
				o2, err2 := strconv.Atoi(m[2])
				o3, err3 := strconv.Atoi(m[3])
				o4, err4 := strconv.Atoi(m[4])
				if err1 == nil && err2 == nil && err3 == nil && err4 == nil &&
					o1 >= 0 && o1 <= 255 && o2 >= 0 && o2 <= 255 &&
					o3 >= 0 && o3 <= 255 && o4 >= 0 && o4 <= 255 {
					addHit("ip")
					return fmt.Sprintf("x.x.x.%d", o4)
				}
			}
			return s
		})
	}

	return result, hits
}

func isMaskIPEnabled() bool {
	v := strings.ToLower(os.Getenv("GOAGENT_MASK_IP"))
	return v == "true" || v == "1"
}

// isValidLuhn checks whether a credit card number passes the Luhn checksum.
func isValidLuhn(s string) bool {
	var digits []int
	for _, r := range s {
		if unicode.IsDigit(r) {
			digits = append(digits, int(r-'0'))
		}
	}

	// Standard credit cards are 13-19 digits, commonly 16
	if len(digits) < 13 || len(digits) > 19 {
		return false
	}

	sum := 0
	alt := false
	for i := len(digits) - 1; i >= 0; i-- {
		d := digits[i]
		if alt {
			d *= 2
			if d > 9 {
				d -= 9
			}
		}
		sum += d
		alt = !alt
	}

	return sum%10 == 0
}
