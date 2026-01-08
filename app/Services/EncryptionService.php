<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
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
                return hash('sha256', $key . $salt, true);
            case 'method2':
                return hash('sha256', $salt . $key, true);
            case 'method3':
                return hash('sha256', strrev($key) . $salt, true);
            default:
                throw new \InvalidArgumentException("Invalid method: {$method}");
        }
    }

    public function encrypt(string $data, string $key): string
    {
        $encrypter = new Encrypter($key, self::CIPHER);
        return $encrypter->encrypt($data);
    }

    public function decrypt(string $encryptedData, string $key): string
    {
        $encrypter = new Encrypter($key, self::CIPHER);
        return $encrypter->decrypt($encryptedData);
    }
}
