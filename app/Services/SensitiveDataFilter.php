<?php

declare(strict_types=1);

namespace App\Services;

class SensitiveDataFilter
{
    private const REDACTED_VALUE = '[REDACTED]';

    /**
     * Field patterns to completely redact
     */
    private const COMPLETE_REDACTION_FIELDS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'api_token',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'secret_key',
        'private_key',
        'auth_token',
        'bearer_token',
        'credit_card',
        'cvv',
        'cvc',
        'ssn',
        'social_security',
        'pin',
    ];

    /**
     * Field patterns to partially redact (show first/last few chars)
     */
    private const PARTIAL_REDACTION_FIELDS = [
        'email',
        'phone',
        'card_number',
        'account_number',
        'iban',
    ];

    /**
     * Filter sensitive data from array recursively
     */
    public function filter(array $data): array
    {
        return $this->filterArray($data);
    }

    private function filterArray(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if ($this->shouldCompletelyRedact($lowerKey)) {
                $filtered[$key] = self::REDACTED_VALUE;
            } elseif ($this->shouldPartiallyRedact($lowerKey)) {
                $filtered[$key] = $this->partiallyRedact($value);
            } elseif (is_array($value)) {
                $filtered[$key] = $this->filterArray($value);
            } elseif (is_object($value)) {
                $filtered[$key] = $this->filterObject($value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function filterObject(object $object): mixed
    {
        if (method_exists($object, 'toArray')) {
            return $this->filterArray($object->toArray());
        }

        return self::REDACTED_VALUE;
    }

    private function shouldCompletelyRedact(string $key): bool
    {
        foreach (self::COMPLETE_REDACTION_FIELDS as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function shouldPartiallyRedact(string $key): bool
    {
        foreach (self::PARTIAL_REDACTION_FIELDS as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function partiallyRedact(mixed $value): string
    {
        if (!is_string($value)) {
            return self::REDACTED_VALUE;
        }

        $length = strlen($value);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        // Show first 2 and last 2 characters
        return substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
    }
}
