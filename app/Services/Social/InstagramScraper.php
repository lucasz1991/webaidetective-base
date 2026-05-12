<?php

namespace App\Services\Social;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

class InstagramScraper
{
    public function scrape(
        string $username,
        string $operationMode = 'analyze',
        ?callable $progress = null,
        array $runtimeConfigOverrides = [],
        int $progressStart = 0,
        int $progressEnd = 100,
    ): array
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

        $runtimeConfigPath = $this->writeRuntimeConfig($runtimeConfigOverrides);
        $stdout = '';
        $stderr = '';
        $stderrBuffer = '';

        try {
            $result = Process::path(base_path())
                ->timeout($this->resolveProcessTimeout($operationMode))
                ->run([
                    $this->resolveNodeBinary(),
                    $nodeScript,
                    $username,
                    $runtimeConfigPath,
                    $operationMode,
                ], function (string $type, string $buffer) use (
                    &$stdout,
                    &$stderr,
                    &$stderrBuffer,
                    $progress,
                    $operationMode,
                    $progressStart,
                    $progressEnd,
                ) {
                    if ($type === SymfonyProcess::OUT) {
                        $stdout .= $buffer;

                        return;
                    }

                    $stderr .= $buffer;
                    $this->handleProgressOutput(
                        $stderrBuffer,
                        $buffer,
                        $operationMode,
                        $progressStart,
                        $progressEnd,
                        $progress,
                    );
                });
        } finally {
            if ($runtimeConfigPath && File::exists($runtimeConfigPath)) {
                File::delete($runtimeConfigPath);
            }
        }

        $output = trim($stdout !== '' ? $stdout : $result->output());
        $errorOutput = trim($stderr !== '' ? $stderr : $result->errorOutput());

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

    private function handleProgressOutput(
        string &$buffer,
        string $chunk,
        string $operationMode,
        int $progressStart,
        int $progressEnd,
        ?callable $progress,
    ): void {
        if (! $progress) {
            return;
        }

        $buffer .= $chunk;
        $lines = preg_split("/\r\n|\n|\r/", $buffer);

        if ($lines === false) {
            return;
        }

        $buffer = array_pop($lines) ?? '';

        foreach ($lines as $line) {
            $event = $this->parseProgressLine($line);

            if (! $event) {
                continue;
            }

            $progress($this->normalizeProgressEvent($event, $operationMode, $progressStart, $progressEnd));
        }
    }

    private function parseProgressLine(string $line): ?array
    {
        $prefix = '[SCRAPER PROGRESS] ';

        if (! Str::startsWith($line, $prefix)) {
            return null;
        }

        $payload = json_decode(Str::after($line, $prefix), true);

        return is_array($payload) ? $payload : null;
    }

    private function normalizeProgressEvent(array $event, string $operationMode, int $progressStart, int $progressEnd): array
    {
        $phase = $event['relationship'] ?? $operationMode;
        $loaded = (int) ($event['loaded'] ?? 0);
        $expected = (int) ($event['expectedCount'] ?? 0);
        $round = (int) ($event['round'] ?? 0);
        $maxRounds = max(1, (int) ($event['maxScrollRounds'] ?? 1));
        $stage = (string) ($event['stage'] ?? '');
        $phasePercent = match ($stage) {
            'relationship-opening' => 2,
            'relationship-dialog-missing' => 100,
            'relationship-complete' => 100,
            'profile-page-loaded' => 45,
            'profile-collected' => 100,
            default => $expected > 0
                ? min(99, (int) floor(($loaded / max(1, $expected)) * 100))
                : min(95, (int) floor(($round / $maxRounds) * 100)),
        };
        $overallPercent = $progressStart + (int) floor((max(0, min(100, $phasePercent)) / 100) * max(1, $progressEnd - $progressStart));

        return [
            'phase' => $phase,
            'stage' => $stage,
            'percent' => max($progressStart, min($progressEnd, $overallPercent)),
            'loaded' => $loaded,
            'expected' => $expected,
            'round' => $round,
            'message' => $this->buildProgressMessage($phase, $stage, $loaded, $expected),
        ];
    }

    private function buildProgressMessage(string $phase, string $stage, int $loaded, int $expected): string
    {
        if ($phase === 'followers') {
            return $expected > 0
                ? 'Followerliste wird geladen: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.')
                : 'Followerliste wird geladen: '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
        }

        if ($phase === 'following') {
            return $expected > 0
                ? 'Gefolgt-Liste wird geladen: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.')
                : 'Gefolgt-Liste wird geladen: '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
        }

        return match ($stage) {
            'profile-page-loaded' => 'Profilseite geladen, Grunddaten werden ausgelesen.',
            'profile-collected' => 'Grunddaten wurden ausgelesen.',
            default => 'Instagram-Grunddaten werden geladen.',
        };
    }

    private function resolveProcessTimeout(string $operationMode): int
    {
        $profile = Setting::getValue('scraper', 'instagram_profile');
        $profile = is_array($profile) ? $profile : [];

        if (in_array($operationMode, ['followers', 'following'], true)) {
            return max(14400, (int) ($profile['relationship_list_process_timeout_seconds'] ?? 14400));
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

    private function writeRuntimeConfig(array $overrides = []): string
    {
        $directory = storage_path('app/tmp');
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.'instagram-scraper-config-'.Str::uuid().'.json';
        File::put($path, json_encode([
            ...$this->buildRuntimeConfig(),
            ...$overrides,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
            'relationshipListMaxScrollRounds' => max(20, (int) ($profile['relationship_list_max_scroll_rounds'] ?? 100000)),
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
