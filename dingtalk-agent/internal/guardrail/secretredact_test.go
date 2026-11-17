package guardrail

import (
	"os"
	"strings"
	"testing"
)

func TestSecretRedact(t *testing.T) {
	tests := []struct {
		name          string
		input         string
		expectedSub   string
		expectedHit   string
		unexpectedSub string
	}{
		{
			name:          "OpenAI API key",
			input:         "Config has api_key=sk-abcdef1234567890abcdef123456",
			expectedSub:   "[REDACTED:api_key]",
			expectedHit:   "api_key",
			unexpectedSub: "sk-abcdef1234567890abcdef123456",
		},
		{
			name:          "GitHub PAT",
			input:         "Use token ghp_1234567890abcdef1234567890 to clone",
			expectedSub:   "[REDACTED:api_key]",
			expectedHit:   "api_key",
			unexpectedSub: "ghp_1234567890abcdef1234567890",
		},
		{
			name:          "AWS Access Key",
			input:         "Found AKIAIOSFODNN7EXAMPLE in config",
			expectedSub:   "[REDACTED:api_key]",
			expectedHit:   "api_key",
			unexpectedSub: "AKIAIOSFODNN7EXAMPLE",
		},
		{
			name:          "JWT Token",
			input:         "Token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c",
			expectedSub:   "[REDACTED:jwt]",
			expectedHit:   "jwt",
			unexpectedSub: "eyJhbGciOi",
		},
		{
			name:          "Bearer Token",
			input:         "Authorization: Bearer mySecretToken12345678901234567890",
			expectedSub:   "Bearer [REDACTED:token]",
			expectedHit:   "token",
			unexpectedSub: "mySecretToken12345678901234567890",
		},
		{
			name:          "PostgreSQL connection string with password",
			input:         "connect string: postgres://admin:super_secret_pw123@db.example.com:5432/mydb",
			expectedSub:   "postgres://admin:[REDACTED:password]@db.example.com:5432/mydb",
			expectedHit:   "password",
			unexpectedSub: "super_secret_pw123",
		},
		{
			name:          "Private Key block",
			input:         "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA0Y123...\n-----END RSA PRIVATE KEY-----",
			expectedSub:   "[REDACTED:private_key]",
			expectedHit:   "private_key",
			unexpectedSub: "MIIEowIBAAKCAQEA0Y123",
		},
		{
			name:          "Password key-value",
			input:         "user=john; password=verySecretPassword42; env=prod",
			expectedSub:   "password=[REDACTED:password]",
			expectedHit:   "password",
			unexpectedSub: "verySecretPassword42",
		},
		{
			name:          "Email address",
			input:         "Contact alice.dev@example.org for support",
			expectedSub:   "[REDACTED:email]",
			expectedHit:   "email",
			unexpectedSub: "alice.dev@example.org",
		},
		{
			name: "Luhn valid credit card",
			// 4111-1111-1111-1111 is a classic test Visa passing Luhn
			input:         "Card: 4111-1111-1111-1111 charged $50",
			expectedSub:   "[REDACTED:card]",
			expectedHit:   "card",
			unexpectedSub: "4111-1111-1111-1111",
		},
		{
			name:        "Luhn invalid card number (should NOT be redacted as card)",
			input:       "Serial number: 1234-5678-1234-5678 (not a valid card)",
			expectedSub: "1234-5678-1234-5678",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			redacted, hits := Redact(tt.input)

			if !strings.Contains(redacted, tt.expectedSub) {
				t.Errorf("expected redacted output to contain %q, got: %s", tt.expectedSub, redacted)
			}
			if tt.unexpectedSub != "" && strings.Contains(redacted, tt.unexpectedSub) {
				t.Errorf("redacted output still contains secret %q: %s", tt.unexpectedSub, redacted)
			}

			if tt.expectedHit != "" {
				hitFound := false
				for _, h := range hits {
					if h == tt.expectedHit {
						hitFound = true
						break
					}
				}
				if !hitFound {
					t.Errorf("expected hit %q in hits %v", tt.expectedHit, hits)
				}
			}
		})
	}
}

func TestIPAddressMasking(t *testing.T) {
	sample := "Connection from 192.168.1.100 to 10.0.0.42 established"

	// Default: IP should NOT be masked
	os.Unsetenv("GOAGENT_MASK_IP")
	out, hits := Redact(sample)
	if !strings.Contains(out, "192.168.1.100") || !strings.Contains(out, "10.0.0.42") {
		t.Errorf("IP address was unexpectedly redacted without GOAGENT_MASK_IP: %s", out)
	}
	for _, h := range hits {
		if h == "ip" {
			t.Errorf("unexpected 'ip' hit when GOAGENT_MASK_IP is unset")
		}
	}

	// With GOAGENT_MASK_IP=true: IP should be masked to x.x.x.<last-octet>
	os.Setenv("GOAGENT_MASK_IP", "true")
	defer os.Unsetenv("GOAGENT_MASK_IP")

	masked, mHits := Redact(sample)
	if strings.Contains(masked, "192.168.1.100") {
		t.Errorf("expected IP to be masked, got: %s", masked)
	}
	if !strings.Contains(masked, "x.x.x.100") || !strings.Contains(masked, "x.x.x.42") {
		t.Errorf("expected masked IPs x.x.x.100 and x.x.x.42, got: %s", masked)
	}

	foundIPHit := false
	for _, h := range mHits {
		if h == "ip" {
			foundIPHit = true
			break
		}
	}
	if !foundIPHit {
		t.Errorf("expected 'ip' hit in mHits: %v", mHits)
	}
}
