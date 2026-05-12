<?php

namespace App\Services\Social;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstagramScraper
{
    public function scrape(string $username, string $operationMode = 'analyze'): array
    {
        $username = $this->normalizeInstagramUsername($username);
        $operationMode = $this->normalizeOperationMode($operationMode);

        if ($username === null) {
            throw new \RuntimeException('Bitte einen gueltigen Instagram-Username eingeben.');
        }

        $nodeScript = base_path('resources/node/scraper/scrape-instagram.cjs');

        if (! File::exists($nodeScript)) {
            throw new \RuntimeException('Node-Skript nicht gefunden.');
        }

        $runtimeConfigPath = $this->writeRuntimeConfig();

        try {
            $result = Process::path(base_path())
                ->timeout($this->resolveProcessTimeout($operationMode))
                ->run([
                    $this->resolveNodeBinary(),
                    $nodeScript,
                    $username,
                    $runtimeConfigPath,
                    $operationMode,
                ]);
        } finally {
            if ($runtimeConfigPath && File::exists($runtimeConfigPath)) {
                File::delete($runtimeConfigPath);
            }
        }

        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());

        if ($output === '') {
            throw new \RuntimeException(
                $errorOutput !== ''
                    ? 'Fehler: Kein Output vom Node-Skript. '.$errorOutput
                    : 'Fehler: Kein Output vom Node-Skript.'
            );
        }

        $payload = json_decode($output, true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Fehler: Unerwartetes Output-Format vom Node-Skript.');
        }

        $payload['_process_successful'] = $result->successful();
        $payload['_stderr'] = $errorOutput;
        $payload['username'] = $payload['username'] ?? $username;
        $payload['operationMode'] = $payload['operationMode'] ?? $operationMode;

        return $payload;
    }

    private function resolveProcessTimeout(string $operationMode): int
    {
        $profile = Setting::getValue('scraper', 'instagram_profile');
        $profile = is_array($profile) ? $profile : [];

        if (in_array($operationMode, ['followers', 'following'], true)) {
            return max(240, (int) ($profile['relationship_list_process_timeout_seconds'] ?? 900));
        }

        return max(120, (int) ($profile['profile_process_timeout_seconds'] ?? 240));
    }

    private function normalizeOperationMode(string $operationMode): string
    {
        $operationMode = Str::lower(trim($operationMode));

        return in_array($operationMode, ['analyze', 'profile', 'followers', 'following', 'login-session'], true)
            ? $operationMode
            : 'analyze';
    }

    private function writeRuntimeConfig(): string
    {
        $directory = storage_path('app/tmp');
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.'instagram-scraper-config-'.Str::uuid().'.json';
        File::put($path, json_encode($this->buildRuntimeConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function buildRuntimeConfig(): array
    {
        $profile = Setting::getValue('scraper', 'instagram_profile');
        $profile = is_array($profile) ? $profile : [];
        $runtimePassword = $this->resolveRuntimePassword($profile);

        return [
            'profileLabel' => (string) ($profile['profile_label'] ?? 'instagram-default'),
            'persistentProfileEnabled' => (bool) ($profile['persistent_profile_enabled'] ?? true),
            'browserProfilePath' => $this->resolveStorageAwarePath($profile['browser_profile_path'] ?? 'browser-profiles/instagram/default'),
            'cookieFilePath' => $this->resolveStorageAwarePath($profile['cookie_file_path'] ?? 'cookies/instagram-cookies.json'),
            // Reguläre Analysen laufen immer unsichtbar im Hintergrund.
            'headlessEnabled' => true,
            'autoLoginEnabled' => (bool) ($profile['auto_login_enabled'] ?? false),
            'loginUsername' => trim((string) ($profile['login_username'] ?? '')),
            'loginPassword' => $runtimePassword['password'],
            'loginPasswordConfigured' => $runtimePassword['configured'],
            'loginPasswordDecryptable' => $runtimePassword['decryptable'],
            'loginPasswordSource' => $runtimePassword['source'],
            'navigationTimeoutMs' => max(30000, ((int) ($profile['navigation_timeout_seconds'] ?? 120)) * 1000),
            'postLoginWaitMs' => max(500, (int) ($profile['post_login_wait_ms'] ?? 2500)),
            'typingDelayMs' => max(0, (int) ($profile['typing_delay_ms'] ?? 35)),
            'followerListMaxItems' => max(0, (int) ($profile['follower_list_max_items'] ?? 0)),
            'followingListMaxItems' => max(0, (int) ($profile['following_list_max_items'] ?? 0)),
            'relationshipListMaxScrollRounds' => max(20, (int) ($profile['relationship_list_max_scroll_rounds'] ?? 1000)),
        ];
    }

    private function resolveRuntimePassword(array $profile): array
    {
        $candidates = [
            'login_password_base_encrypted' => $profile['login_password_base_encrypted'] ?? null,
            'login_password_encrypted' => $profile['login_password_encrypted'] ?? null,
        ];
        $configured = false;

        foreach ($candidates as $source => $encryptedPassword) {
            if (! is_string($encryptedPassword) || trim($encryptedPassword) === '') {
                continue;
            }

            $configured = true;
            $decrypted = $this->decryptRuntimePassword($encryptedPassword);

            if ($decrypted !== null) {
                return [
                    'password' => $decrypted,
                    'configured' => true,
                    'decryptable' => true,
                    'source' => $source,
                ];
            }
        }

        return [
            'password' => null,
            'configured' => $configured,
            'decryptable' => ! $configured,
            'source' => null,
        ];
    }

    private function decryptRuntimePassword(mixed $encryptedPassword): ?string
    {
        if (! is_string($encryptedPassword) || trim($encryptedPassword) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encryptedPassword);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveStorageAwarePath(mixed $configuredPath): string
    {
        $configuredPath = trim((string) $configuredPath);

        if ($configuredPath === '') {
            return storage_path('app');
        }

        if ($this->isAbsolutePath($configuredPath)) {
            return $configuredPath;
        }

        return storage_path('app'.DIRECTORY_SEPARATOR.ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configuredPath), DIRECTORY_SEPARATOR));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    public function normalizeInstagramUsername(?string $username): ?string
    {
        $username = trim((string) $username);
        $username = ltrim($username, '@');

        if ($username === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9._]+$/', $username)) {
            return null;
        }

        return $username;
    }

    public function resolveNodeBinary(): string
    {
        $configuredBinary = config('services.node.binary');

        if (is_string($configuredBinary) && $configuredBinary !== '') {
            $configuredBinary = trim($configuredBinary, " \t\n\r\0\x0B\"'");

            if (File::exists($configuredBinary)) {
                return $configuredBinary;
            }
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\\Program Files\\nodejs\\node.exe',
                'C:\\Program Files (x86)\\nodejs\\node.exe',
            ]
            : [
                '/usr/bin/node',
                '/usr/local/bin/node',
            ];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            PHP_OS_FAMILY === 'Windows'
                ? 'Node.js wurde nicht gefunden. Bitte in der .env z. B. NODE_BINARY="C:\\Program Files\\nodejs\\node.exe" setzen.'
                : 'Node.js wurde nicht gefunden. Bitte in der .env z. B. NODE_BINARY="/usr/bin/node" setzen.'
        );
    }

    public function resolvePublicStoragePath(?string $absolutePath): ?string
    {
        if (! $absolutePath) {
            return null;
        }

        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath);

        if (! File::exists($normalizedPath)) {
            return null;
        }

        $storagePublicPath = storage_path('app/public').DIRECTORY_SEPARATOR;

        if (Str::startsWith($normalizedPath, $storagePublicPath)) {
            return str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                Str::after($normalizedPath, $storagePublicPath),
            );
        }

        return null;
    }

    public function resolvePublicStorageUrl(?string $absolutePath): ?string
    {
        $relativePath = $this->resolvePublicStoragePath($absolutePath);

        return $relativePath ? Storage::disk('public')->url($relativePath) : null;
    }
}
