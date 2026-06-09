<?php

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
            if (str_starts_with($decrypted, 's:') && preg_match('/^s:\d+:"/', $decrypted)) {
                $unserialized = @unserialize($decrypted);
                if ($unserialized !== false || $decrypted === 'b:0;') {
                    return $unserialized;
                }
            }

            return $decrypted;
        } catch (\Exception $e) {
            // Fall back to serialized decryption for legacy files
            return $encrypter->decrypt($encryptedData);
        }
    }
}
