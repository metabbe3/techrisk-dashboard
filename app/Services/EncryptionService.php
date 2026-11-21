<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

class EncryptionService
{
    private const CIPHER = 'aes-256-cbc';

    public function generateKey(): string
    {
        return Encrypter::generateKey(self::CIPHER);
    }

    public function generateSalt(): string
    {
        return Str::random(16);
    }

    public function getFinalKey(string $key, string $salt, string $method): string
    {
        switch ($method) {
            case 'method1':
                return hash('sha256', $key.$salt, true);
            case 'method2':
                return hash('sha256', $salt.$key, true);
            case 'method3':
                return hash('sha256', strrev($key).$salt, true);
            default:
                throw new \InvalidArgumentException("Invalid method: {$method}");
        }
    }

    public function encrypt(string $data, string $key): string
    {
        $encrypter = new Encrypter($key, self::CIPHER);

        return $encrypter->encryptString($data);
    }

    public function decrypt(string $encryptedData, string $key): string
    {
        $encrypter = new Encrypter($key, self::CIPHER);

        try {
            $decrypted = $encrypter->decryptString($encryptedData);

            // Legacy files were encrypted with encrypt() which serializes.
            // decryptString() succeeds but returns the raw serialized string.
            // W10 fix: extract the string with a regex instead of
            // @unserialize() — no object injection on the decrypt path.
            if (str_starts_with($decrypted, 's:') && preg_match('/^s:(\d+):"(.*)";$/s', $decrypted, $m)) {
                if (strlen($m[2]) === (int) $m[1]) {
                    return $m[2];
                }
            }

            if ($decrypted === 'b:0;') {
                return '';
            }

            return $decrypted;
        } catch (\Throwable $e) {
            // W10 fix: the old catch fell back to Encrypter::decrypt(),
            // which unserializes — another object-injection sink. A payload
            // decryptString() rejects is corrupt or key-mismatched; surface
            // it (callers abort with a 500 either way).
            throw new \RuntimeException('Decryption failed: the file is corrupted or the key changed.', 0, $e);
        }
    }
}
