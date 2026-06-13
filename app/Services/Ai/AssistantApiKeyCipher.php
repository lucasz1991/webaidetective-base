<?php

namespace App\Services\Ai;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

class AssistantApiKeyCipher
{
    public function encrypt(string $value): string
    {
        return $this->encrypter()->encryptString(trim($value));
    }

    public function decrypt(string $value): string
    {
        try {
            return trim($this->encrypter()->decryptString($value));
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Der gespeicherte AI API-Key kann nicht entschluesselt werden. Bitte den Key im Adminbereich erneut speichern.',
                previous: $exception,
            );
        }
    }

    private function encrypter(): Encrypter
    {
        $configuredKey = trim((string) config('services.ai_assistant.encryption_key'));

        if ($configuredKey === '') {
            throw new RuntimeException('AI_ASSISTANT_ENCRYPTION_KEY ist nicht konfiguriert.');
        }

        $key = str_starts_with($configuredKey, 'base64:')
            ? base64_decode(substr($configuredKey, 7), true)
            : $configuredKey;

        if (! is_string($key) || ! Encrypter::supported($key, 'AES-256-CBC')) {
            throw new RuntimeException('AI_ASSISTANT_ENCRYPTION_KEY ist ungueltig.');
        }

        return new Encrypter($key, 'AES-256-CBC');
    }
}
