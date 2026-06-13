<?php

namespace App\Services\Social;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Models\Setting;
use App\Services\Scraper\ScraperProfileDatabaseStore;
use App\Services\TrackedPeople\InstagramScanPolicyService;
use App\Services\TrackedPeople\TrackedPersonInstagramScanCoordinator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

class InstagramScraper
{
    public function __construct(
        private readonly InstagramScanPolicyService $scanPolicies,
    ) {}

    public function scrape(
        string $username,
        string $operationMode = 'analyze',
        ?callable $progress = null,
        array $runtimeConfigOverrides = [],
        int $progressStart = 0,
        int $progressEnd = 100,
    ): array {
        $runtimeConfigOverrides = [
            ...$this->scanPolicies->runtimeOverrides($operationMode),
            ...$runtimeConfigOverrides,
        ];

        return $this->runWithRetries(
            $operationMode,
            $progress,
            fn (): array => $this->scrapeOnce(
                $username,
                $operationMode,
                $progress,
                $runtimeConfigOverrides,
                $progressStart,
                $progressEnd,
            ),
        );
    }

    private function scrapeOnce(
        string $username,
        string $operationMode = 'analyze',
        ?callable $progress = null,
        array $runtimeConfigOverrides = [],
        int $progressStart = 0,
        int $progressEnd = 100,
    ): array {
        $username = $this->normalizeInstagramUsername($username);
        $operationMode = $this->normalizeOperationMode($operationMode);

        if ($username === null) {
            throw new \RuntimeException('Bitte einen gueltigen Instagram-Username eingeben.');
        }

        $nodeScript = $this->resolveNodeScriptForOperationMode($operationMode);
        // Override: use dedicated suggestion entrypoints that emit JSON
        if ($operationMode === 'suggestions') {
            $nodeScript = base_path('resources/node/scraper/scrape-instagram-suggestions-basic.cjs');
        } elseif ($operationMode === 'suggestion-connections') {
            $nodeScript = base_path('resources/node/scraper/scrape-instagram-suggestions-deepsearch.cjs');
        }

        if (! File::exists($nodeScript)) {
            throw new \RuntimeException('Node-Skript fuer Instagram-'.$operationMode.' nicht gefunden.');
        }

        $scanControl = $this->extractScanControl($runtimeConfigOverrides);
        if ($scanControl !== null) {
            $scanControl['processStallTimeoutSeconds'] = $this->scanPolicies->processStallTimeoutSeconds();
        }
        $runtimeConfigOverrides = $this->withScanControlRuntimeConfig($runtimeConfigOverrides, $scanControl);
        $runtimeConfigPath = $this->writeRuntimeConfig($runtimeConfigOverrides);
        $stdout = '';
        $stderr = '';
        $stderrBuffer = '';

        try {
            $result = $this->runNodeProcess(
                [
                    $this->resolveNodeBinary(),
                    $nodeScript,
                    $username,
                    $runtimeConfigPath,
                    $operationMode,
                ],
                $scanControl,
                $operationMode,
                function (string $type, string $buffer) use (
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
                },
            );
        } finally {
            if ($runtimeConfigPath && File::exists($runtimeConfigPath)) {
                $this->syncScraperProfileCookiesFromRuntimeConfig($runtimeConfigPath);
                File::delete($runtimeConfigPath);
            }
        }

        $output = trim($stdout !== '' ? $stdout : $result['output']);
        $errorOutput = trim($stderr !== '' ? $stderr : $result['errorOutput']);

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

        $payload['_process_successful'] = $result['successful'];
        $payload['_stderr'] = $errorOutput;
        $payload['username'] = $payload['username'] ?? $username;
        $payload['operationMode'] = $payload['operationMode'] ?? $operationMode;

        return $payload;
    }

    public function scanPublicProfileConnection(
        string $publicUsername,
        string $targetUsername,
        ?callable $progress = null,
        array $runtimeConfigOverrides = [],
    ): array {
        $operationMode = 'public-profile-connections';
        $runtimeConfigOverrides = [
            ...$this->scanPolicies->runtimeOverrides($operationMode),
            ...$runtimeConfigOverrides,
        ];

        return $this->runWithRetries(
            $operationMode,
            $progress,
            fn (): array => $this->scanPublicProfileConnectionOnce(
                $publicUsername,
                $targetUsername,
                $progress,
                $runtimeConfigOverrides,
            ),
        );
    }

    private function scanPublicProfileConnectionOnce(
        string $publicUsername,
        string $targetUsername,
        ?callable $progress = null,
        array $runtimeConfigOverrides = [],
    ): array {
        $publicUsername = $this->normalizeInstagramUsername($publicUsername);
        $targetUsername = $this->normalizeInstagramUsername($targetUsername);

        if ($publicUsername === null || $targetUsername === null) {
            throw new \RuntimeException('Bitte gueltige Instagram-Usernames fuer Verbindungsscan angeben.');
        }

        $nodeScript = base_path('resources/node/scraper/scrape-instagram-public-profile-connections.cjs');

        if (! File::exists($nodeScript)) {
            throw new \RuntimeException('Node-Skript fuer Public-Profile-Verbindungsscan nicht gefunden.');
        }

        $scanControl = $this->extractScanControl($runtimeConfigOverrides);
        if ($scanControl !== null) {
            $scanControl['processStallTimeoutSeconds'] = $this->scanPolicies->processStallTimeoutSeconds();
        }
        $runtimeConfigOverrides = $this->withScanControlRuntimeConfig($runtimeConfigOverrides, $scanControl);
        $runtimeConfigPath = $this->writeRuntimeConfig($runtimeConfigOverrides);
        $stdout = '';
        $stderr = '';
        $stderrBuffer = '';
        $lastProgressPercent = 0;

        try {
            $result = $this->runNodeProcess(
                [
                    $this->resolveNodeBinary(),
                    $nodeScript,
                    $publicUsername,
                    $targetUsername,
                    $runtimeConfigPath,
                ],
                $scanControl,
                'public-profile-connections',
                function (string $type, string $buffer) use (
                    &$stdout,
                    &$stderr,
                    &$stderrBuffer,
                    &$lastProgressPercent,
                    $progress,
                ) {
                    if ($type === SymfonyProcess::OUT) {
                        $stdout .= $buffer;

                        return;
                    }

                    $stderr .= $buffer;

                    $stderrBuffer .= $buffer;
                    $lines = preg_split("/\r\n|\n|\r/", $stderrBuffer);

                    if ($lines === false) {
                        return;
                    }

                    $stderrBuffer = array_pop($lines) ?? '';

                    foreach ($lines as $line) {
                        $event = $this->parseProgressLine($line);

                        if (! $event) {
                            continue;
                        }

                        $this->recordScraperProfileRateLimitIfNeeded($event);

                        $relationship = (string) ($event['relationship'] ?? '');

                        if ($relationship === 'public-connections') {
                            $loaded = (int) ($event['loaded'] ?? 0);
                            $expected = max(1, (int) ($event['expectedCount'] ?? 1));
                            $percent = min(99, max(1, (int) floor(($loaded / $expected) * 100)));
                            $percent = max($lastProgressPercent, $percent);
                            $lastProgressPercent = $percent;
                            $foundFollowers = (int) ($event['foundFollowers'] ?? 0);
                            $foundFollowing = (int) ($event['foundFollowing'] ?? 0);
                            $hasInferredFollowers = array_key_exists('inferredFollowersPreview', $event) || array_key_exists('inferredFollowers', $event);
                            $hasInferredFollowing = array_key_exists('inferredFollowingPreview', $event) || array_key_exists('inferredFollowing', $event);
                            $inferredFollowers = $this->normalizeConnectionProgressItems(
                                $event['inferredFollowersPreview'] ?? $event['inferredFollowers'] ?? null,
                            );
                            $inferredFollowing = $this->normalizeConnectionProgressItems(
                                $event['inferredFollowingPreview'] ?? $event['inferredFollowing'] ?? null,
                            );
                            $message = trim((string) ($event['message'] ?? ''));

                            if ($message === '') {
                                $message = 'Kandidaten geprueft: '
                                    .number_format($loaded, 0, ',', '.')
                                    .' von '
                                    .number_format($expected, 0, ',', '.')
                                    .'. Gefunden: '
                                    .number_format($foundFollowers, 0, ',', '.')
                                    .' Follower, '
                                    .number_format($foundFollowing, 0, ',', '.')
                                    .' Gefolgt.';
                            }

                            $progressPayload = [
                                'phase' => 'public-connections',
                                'stage' => (string) ($event['stage'] ?? 'public-connections'),
                                'percent' => $percent,
                                'loaded' => $loaded,
                                'expected' => $expected,
                                'candidateUsername' => $this->normalizeInstagramUsername((string) ($event['candidateUsername'] ?? '')),
                                'candidateConnection' => $this->normalizeCandidateConnectionProgress($event['candidateConnection'] ?? null),
                                'targetFoundInFollowers' => (bool) ($event['targetFoundInFollowers'] ?? false),
                                'targetFoundInFollowing' => (bool) ($event['targetFoundInFollowing'] ?? false),
                                'skippedReason' => is_scalar($event['skippedReason'] ?? null) ? trim((string) $event['skippedReason']) : null,
                                'stoppedForRateLimit' => (bool) ($event['stoppedForRateLimit'] ?? false),
                                'gracefullyStopped' => (bool) ($event['gracefullyStopped'] ?? false),
                                'foundFollowers' => $foundFollowers,
                                'foundFollowing' => $foundFollowing,
                                'rateLimitedCandidates' => (int) ($event['rateLimitedCandidates'] ?? 0),
                                'message' => $message,
                                ...$this->normalizeLiveScreenshotProgress($event),
                            ];

                            if ($hasInferredFollowers) {
                                $progressPayload['inferredFollowers'] = $inferredFollowers;
                            }

                            if ($hasInferredFollowing) {
                                $progressPayload['inferredFollowing'] = $inferredFollowing;
                            }

                            $progressPayload = [
                                ...$progressPayload,
                                ...$this->normalizeScraperProfileProgress($event),
                            ];

                            if ($progress) {
                                $progress($progressPayload);
                            }

                            continue;
                        }

                        continue;
                    }
                },
            );
        } finally {
            if ($runtimeConfigPath && File::exists($runtimeConfigPath)) {
                $this->syncScraperProfileCookiesFromRuntimeConfig($runtimeConfigPath);
                File::delete($runtimeConfigPath);
            }
        }

        $output = trim($stdout !== '' ? $stdout : $result['output']);
        $errorOutput = trim($stderr !== '' ? $stderr : $result['errorOutput']);

        if ($output === '') {
            throw new \RuntimeException(
                $errorOutput !== ''
                    ? 'Fehler: Kein Output vom Public-Profile-Verbindungsscan. '.$errorOutput
                    : 'Fehler: Kein Output vom Public-Profile-Verbindungsscan.'
            );
        }

        $payload = json_decode($output, true);

        if (! is_array($payload)) {
            throw new \RuntimeException('Fehler: Unerwartetes Output-Format vom Public-Profile-Verbindungsscan.');
        }

        $payload['_process_successful'] = $result['successful'];
        $payload['_stderr'] = $errorOutput;
        $payload['publicUsername'] = $payload['publicUsername'] ?? $publicUsername;
        $payload['targetUsername'] = $payload['targetUsername'] ?? $targetUsername;
        $payload['operationMode'] = 'public-profile-connections';

        return $payload;
    }

    private function runWithRetries(
        string $operationMode,
        ?callable $progress,
        callable $callback,
    ): array {
        $maxAttempts = $this->scanPolicies->errorAttempts($operationMode);
        $retryDelayMilliseconds = $this->scanPolicies->retryDelayMilliseconds($operationMode);
        $attemptLog = [];
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $payload = $callback();
                $attemptLog[] = [
                    'attempt' => $attempt,
                    'statusLevel' => $payload['statusLevel'] ?? null,
                    'statusMessage' => $payload['statusMessage'] ?? null,
                    'processSuccessful' => $payload['_process_successful'] ?? null,
                ];

                if (! $this->payloadRequiresRetry($payload) || $attempt >= $maxAttempts) {
                    $payload['_scan_attempt'] = $attempt;
                    $payload['_scan_max_attempts'] = $maxAttempts;
                    $payload['_scan_attempts'] = $attemptLog;

                    return $payload;
                }

                $this->reportRetry(
                    $progress,
                    $operationMode,
                    $attempt + 1,
                    $maxAttempts,
                    (string) ($payload['statusMessage'] ?? $payload['error'] ?? 'Scraper-Fehler'),
                );
            } catch (TrackedPersonInstagramScanCancelledException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $lastException = $exception;
                $attemptLog[] = [
                    'attempt' => $attempt,
                    'exception' => $exception->getMessage(),
                ];

                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }

                $this->reportRetry(
                    $progress,
                    $operationMode,
                    $attempt + 1,
                    $maxAttempts,
                    $exception->getMessage(),
                );
            }

            if ($retryDelayMilliseconds > 0) {
                usleep($retryDelayMilliseconds * 1000);
            }
        }

        throw $lastException ?: new \RuntimeException('Instagram-Scan konnte nicht ausgefuehrt werden.');
    }

    private function payloadRequiresRetry(array $payload): bool
    {
        if (
            (bool) ($payload['gracefullyStopped'] ?? false)
            || (bool) ($payload['stoppedForRateLimit'] ?? false)
            || (bool) data_get($payload, 'suggestionScan.rateLimited', false)
        ) {
            return false;
        }

        $statusLevel = Str::lower(trim((string) ($payload['statusLevel'] ?? '')));

        return $statusLevel === 'error'
            || (($payload['_process_successful'] ?? true) === false && ! (bool) ($payload['ok'] ?? false));
    }

    private function reportRetry(
        ?callable $progress,
        string $operationMode,
        int $nextAttempt,
        int $maxAttempts,
        string $reason,
    ): void {
        if (! $progress) {
            return;
        }

        $progress([
            'phase' => 'retry',
            'percent' => 0,
            'message' => sprintf(
                'Instagram-%s wird nach einem Fehler erneut gestartet (Versuch %d/%d): %s',
                $operationMode,
                $nextAttempt,
                $maxAttempts,
                Str::limit(trim($reason), 180),
            ),
            'attempt' => $nextAttempt,
            'maxAttempts' => $maxAttempts,
        ]);
    }

    private function extractScanControl(array &$runtimeConfigOverrides): ?array
    {
        $scanControl = $runtimeConfigOverrides['_scanControl'] ?? null;
        unset($runtimeConfigOverrides['_scanControl']);

        if (! is_array($scanControl)) {
            return null;
        }

        $trackedPersonId = (int) ($scanControl['trackedPersonId'] ?? 0);
        $generation = (int) ($scanControl['generation'] ?? 0);

        if ($trackedPersonId <= 0 || $generation <= 0) {
            return null;
        }

        return [
            'trackedPersonId' => $trackedPersonId,
            'generation' => $generation,
            'label' => (string) ($scanControl['label'] ?? 'Instagram-Scan'),
            'gracefulStopFilePath' => is_string($scanControl['gracefulStopFilePath'] ?? null)
                ? (string) $scanControl['gracefulStopFilePath']
                : null,
            'processStallTimeoutSeconds' => (int) ($scanControl['processStallTimeoutSeconds'] ?? 900),
        ];
    }

    private function withScanControlRuntimeConfig(array $runtimeConfigOverrides, ?array $scanControl): array
    {
        $gracefulStopFilePath = $scanControl['gracefulStopFilePath'] ?? null;

        if (! is_string($gracefulStopFilePath) || $gracefulStopFilePath === '') {
            return $runtimeConfigOverrides;
        }

        return [
            ...$runtimeConfigOverrides,
            'gracefulStopFilePath' => $gracefulStopFilePath,
        ];
    }

    private function runNodeProcess(
        array $command,
        ?array $scanControl,
        string $label,
        callable $onOutput,
    ): array {
        $process = new SymfonyProcess($command, base_path());
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->start();

        $processCoordinator = app(TrackedPersonInstagramScanCoordinator::class);
        $coordinator = $scanControl ? $processCoordinator : null;
        $trackedPersonId = (int) ($scanControl['trackedPersonId'] ?? 0);
        $generation = (int) ($scanControl['generation'] ?? 0);
        $pid = (int) ($process->getPid() ?? 0);

        if ($coordinator && $pid > 0) {
            $coordinator->registerProcess(
                $trackedPersonId,
                $generation,
                $pid,
                trim(($scanControl['label'] ?? 'Instagram-Scan').' '.$label),
            );
        }

        $stdout = '';
        $stderr = '';
        $lastOutputAt = microtime(true);
        $processStallTimeoutSeconds = max(
            60,
            (int) ($scanControl['processStallTimeoutSeconds'] ?? $this->scanPolicies->processStallTimeoutSeconds()),
        );

        try {
            while ($process->isRunning()) {
                $stdoutChunk = $process->getIncrementalOutput();
                $stderrChunk = $process->getIncrementalErrorOutput();

                if ($stdoutChunk !== '') {
                    $lastOutputAt = microtime(true);
                    $stdout .= $stdoutChunk;
                    $onOutput(SymfonyProcess::OUT, $stdoutChunk);
                }

                if ($stderrChunk !== '') {
                    $lastOutputAt = microtime(true);
                    $stderr .= $stderrChunk;
                    $onOutput(SymfonyProcess::ERR, $stderrChunk);
                }

                if ($coordinator && $coordinator->shouldCancel($trackedPersonId, $generation)) {
                    $process->stop(1);

                    if ($pid > 0) {
                        $coordinator->terminateProcessTree($pid);
                    }

                    throw new TrackedPersonInstagramScanCancelledException(
                        'Instagram-Scan wurde abgebrochen, weil fuer diese Person ein neuer Scan gestartet wurde.'
                    );
                }

                if ((microtime(true) - $lastOutputAt) >= $processStallTimeoutSeconds) {
                    $process->stop(1);

                    if ($pid > 0) {
                        $processCoordinator->terminateProcessTree($pid);
                    }

                    throw new \RuntimeException(
                        'Node.js-Scraper wurde beendet, weil seit '.$processStallTimeoutSeconds.' Sekunden kein Output mehr kam.'
                    );
                }

                usleep(150000);
            }

            $stdoutChunk = $process->getIncrementalOutput();
            $stderrChunk = $process->getIncrementalErrorOutput();

            if ($stdoutChunk !== '') {
                $stdout .= $stdoutChunk;
                $onOutput(SymfonyProcess::OUT, $stdoutChunk);
            }

            if ($stderrChunk !== '') {
                $stderr .= $stderrChunk;
                $onOutput(SymfonyProcess::ERR, $stderrChunk);
            }

            if ($coordinator) {
                $coordinator->assertCurrent($trackedPersonId, $generation);
            }

            return [
                'successful' => $process->isSuccessful(),
                'output' => $stdout !== '' ? $stdout : $process->getOutput(),
                'errorOutput' => $stderr !== '' ? $stderr : $process->getErrorOutput(),
            ];
        } finally {
            if ($coordinator && $pid > 0) {
                $coordinator->unregisterProcess($trackedPersonId, $generation, $pid);
            }
        }
    }

    private function handleProgressOutput(
        string &$buffer,
        string $chunk,
        string $operationMode,
        int $progressStart,
        int $progressEnd,
        ?callable $progress,
    ): void {
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

            $this->recordScraperProfileRateLimitIfNeeded($event);

            if ($progress) {
                $progress($this->normalizeProgressEvent($event, $operationMode, $progressStart, $progressEnd));
            }
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

    private function recordScraperProfileRateLimitIfNeeded(array $event): void
    {
        if (! $this->isInstagramRateLimitEvent($event)) {
            return;
        }

        $profile = is_array($event['scraperProfile'] ?? null) ? $event['scraperProfile'] : [];
        $profileId = $this->nullableTrim($event['scraperProfileId'] ?? $profile['id'] ?? null);

        if ($profileId === null) {
            return;
        }

        try {
            app(ScraperProfileDatabaseStore::class)->blockProfileForInstagramLimit(
                $profileId,
                $this->rateLimitBlockReason($event),
                3600,
                [
                    'stage' => $this->nullableTrim($event['stage'] ?? null),
                    'relationship' => $this->nullableTrim($event['relationship'] ?? null),
                    'message' => $this->nullableTrim($event['message'] ?? null),
                    'rateLimitText' => Str::limit((string) ($event['rateLimitText'] ?? ''), 500),
                    'scraperProfileLabel' => $this->nullableTrim($event['scraperProfileLabel'] ?? $profile['label'] ?? null),
                    'scraperProfileLoginUsername' => $this->nullableTrim($event['scraperProfileLoginUsername'] ?? $profile['loginUsername'] ?? null),
                ],
            );
        } catch (\Throwable) {
            // Die Sperrmarkierung darf den laufenden Scan nicht abbrechen.
        }
    }

    private function isInstagramRateLimitEvent(array $event): bool
    {
        $stage = Str::lower((string) ($event['stage'] ?? ''));
        $reason = Str::lower((string) ($event['reason'] ?? $event['searchStopReason'] ?? ''));
        $message = Str::lower((string) ($event['message'] ?? ''));
        $rateLimitText = Str::lower((string) ($event['rateLimitText'] ?? ''));

        return (bool) ($event['rateLimited'] ?? false)
            || (bool) ($event['stoppedForRateLimit'] ?? false)
            || Str::contains($stage, ['rate-limit', 'rate_limited'])
            || Str::contains($reason, ['instagram-rate-limit', 'rate-limit', 'rate_limited'])
            || Str::contains($message, ['rate-limit', 'rate limit'])
            || Str::contains($rateLimitText, [
                'try again later',
                'we restrict certain activity',
                'we limit how often',
                'versuche es sp',
                'wir schr',
            ]);
    }

    private function rateLimitBlockReason(array $event): string
    {
        $stage = $this->nullableTrim($event['stage'] ?? null);
        $relationship = $this->nullableTrim($event['relationship'] ?? null);

        return trim('instagram-rate-limit'.($relationship ? ':'.$relationship : '').($stage ? ':'.$stage : ''));
    }

    private function normalizeConnectionProgressItems(mixed $items, int $limit = 40): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalizedItems = [];

        foreach (array_slice($items, 0, max(1, $limit)) as $item) {
            if (! is_array($item) || ! is_scalar($item['username'] ?? null)) {
                continue;
            }

            $username = $this->normalizeInstagramUsername((string) $item['username']);

            if ($username === null) {
                continue;
            }

            $normalizedItems[] = [
                'username' => $username,
                'displayName' => is_scalar($item['displayName'] ?? null) ? trim((string) $item['displayName']) : null,
                'profileUrl' => is_scalar($item['profileUrl'] ?? null)
                    ? trim((string) $item['profileUrl'])
                    : 'https://www.instagram.com/'.$username.'/',
                'profileImageUrl' => is_scalar($item['profileImageUrl'] ?? $item['profile_image_url'] ?? null)
                    ? trim((string) ($item['profileImageUrl'] ?? $item['profile_image_url']))
                    : null,
                'profileVisibility' => in_array(($item['profileVisibility'] ?? null), ['public', 'private', 'unknown'], true)
                    ? $item['profileVisibility']
                    : null,
                'isPrivate' => is_bool($item['isPrivate'] ?? null) ? $item['isPrivate'] : null,
                'postsCount' => is_numeric($item['postsCount'] ?? null) ? max(0, (int) $item['postsCount']) : null,
                'followersCount' => is_numeric($item['followersCount'] ?? null) ? max(0, (int) $item['followersCount']) : null,
                'followingCount' => is_numeric($item['followingCount'] ?? null) ? max(0, (int) $item['followingCount']) : null,
                'hoverCard' => is_array($item['hoverCard'] ?? null) ? $item['hoverCard'] : null,
                'sourceSuggestionUsername' => $this->normalizeInstagramUsername((string) ($item['sourceSuggestionUsername'] ?? $item['sourcePublicUsername'] ?? '')),
                'sourcePublicUsername' => $this->normalizeInstagramUsername((string) ($item['sourcePublicUsername'] ?? $item['sourceSuggestionUsername'] ?? '')),
                'sourceLists' => is_array($item['sourceLists'] ?? null) ? array_values(array_filter($item['sourceLists'], 'is_scalar')) : [],
                'targetFoundAsSuggestion' => array_key_exists('targetFoundAsSuggestion', $item)
                    ? (bool) $item['targetFoundAsSuggestion']
                    : true,
                'targetFoundInPublicLists' => (bool) ($item['targetFoundInPublicLists'] ?? false),
                'targetFoundInFollowers' => (bool) ($item['targetFoundInFollowers'] ?? false),
                'targetFoundInFollowing' => (bool) ($item['targetFoundInFollowing'] ?? false),
                'publicListSearch' => is_array($item['publicListSearch'] ?? null)
                    ? $item['publicListSearch']
                    : [],
                'checked' => array_key_exists('checked', $item) ? (bool) $item['checked'] : null,
                'skipped' => array_key_exists('skipped', $item) ? (bool) $item['skipped'] : null,
                'matched' => array_key_exists('matched', $item) ? (bool) $item['matched'] : null,
                'alreadyKnown' => (bool) ($item['alreadyKnown'] ?? false),
                'dismissedFromSuggestions' => (bool) ($item['dismissedFromSuggestions'] ?? false),
                'skippedReason' => $this->nullableTrim($item['skippedReason'] ?? null),
                'previousNoMatchChecks' => is_numeric($item['previousNoMatchChecks'] ?? null)
                    ? max(0, (int) $item['previousNoMatchChecks'])
                    : null,
            ];
        }

        return $normalizedItems;
    }

    private function normalizeCandidateConnectionProgress(mixed $connection): ?array
    {
        if (! is_array($connection) || ! is_scalar($connection['username'] ?? null)) {
            return null;
        }

        $username = $this->normalizeInstagramUsername((string) $connection['username']);

        if ($username === null) {
            return null;
        }

        return [
            'username' => $username,
            'displayName' => $this->nullableTrim($connection['displayName'] ?? null),
            'profileUrl' => $this->nullableTrim($connection['profileUrl'] ?? null)
                ?: 'https://www.instagram.com/'.$username.'/',
            'profileImageUrl' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
            'profileVisibility' => in_array(($connection['profileVisibility'] ?? null), ['public', 'private', 'unknown'], true)
                ? $connection['profileVisibility']
                : null,
            'isPrivate' => is_bool($connection['isPrivate'] ?? null) ? $connection['isPrivate'] : null,
            'postsCount' => is_numeric($connection['postsCount'] ?? null) ? max(0, (int) $connection['postsCount']) : null,
            'followersCount' => is_numeric($connection['followersCount'] ?? null) ? max(0, (int) $connection['followersCount']) : null,
            'followingCount' => is_numeric($connection['followingCount'] ?? null) ? max(0, (int) $connection['followingCount']) : null,
            'hoverCard' => is_array($connection['hoverCard'] ?? null) ? $connection['hoverCard'] : null,
            'sourceLists' => is_array($connection['sourceLists'] ?? null)
                ? array_values(array_filter($connection['sourceLists'], 'is_scalar'))
                : [],
            'followerOfPrivateProfile' => (bool) ($connection['followerOfPrivateProfile'] ?? false),
            'followedByPrivateProfile' => (bool) ($connection['followedByPrivateProfile'] ?? false),
            'candidateFollowsTarget' => (bool) ($connection['candidateFollowsTarget'] ?? false),
            'targetFollowsCandidate' => (bool) ($connection['targetFollowsCandidate'] ?? false),
            'skippedReason' => $this->nullableTrim($connection['skippedReason'] ?? null),
            'gracefullyStopped' => (bool) ($connection['gracefullyStopped'] ?? false),
            'scanAttempts' => is_numeric($connection['scanAttempts'] ?? null) ? max(1, (int) $connection['scanAttempts']) : null,
            'followers' => is_array($connection['followers'] ?? null) ? $connection['followers'] : null,
            'following' => is_array($connection['following'] ?? null) ? $connection['following'] : null,
            'debugScreenshotPaths' => is_array($connection['debugScreenshotPaths'] ?? null)
                ? array_values(array_filter($connection['debugScreenshotPaths'], 'is_scalar'))
                : [],
        ];
    }

    private function normalizeSuggestionProgress(array $event): array
    {
        if (
            ! array_key_exists('suggestionConnectionsPreview', $event)
            && ! array_key_exists('suggestionConnections', $event)
            && ! array_key_exists('foundSuggestions', $event)
            && ! array_key_exists('observedSuggestionsPreview', $event)
            && ! array_key_exists('observedSuggestions', $event)
            && ! array_key_exists('suggestionsObserved', $event)
            && ! array_key_exists('suggestionDebug', $event)
            && ! array_key_exists('suggestionCollectionPhase', $event)
        ) {
            return [];
        }

        $progress = [
            'foundSuggestions' => (int) ($event['foundSuggestions'] ?? 0),
            'suggestionConnections' => $this->normalizeConnectionProgressItems(
                $event['suggestionConnectionsPreview'] ?? $event['suggestionConnections'] ?? null,
            ),
            'observedSuggestionCount' => (int) ($event['observedSuggestionCount'] ?? $event['suggestionsObserved'] ?? 0),
            'knownSuggestionCount' => (int) ($event['knownSuggestionCount'] ?? $event['knownObservedSuggestions'] ?? $event['suggestionKnownSeen'] ?? 0),
            'skippedSuggestions' => (int) ($event['skippedSuggestions'] ?? 0),
            'dismissedSuggestions' => (int) ($event['dismissedSuggestions'] ?? 0),
            'observedSuggestions' => $this->normalizeConnectionProgressItems(
                $event['observedSuggestionsPreview'] ?? $event['observedSuggestions'] ?? null,
            ),
        ];

        if (array_key_exists('suggestionDebug', $event) || array_key_exists('suggestionCollectionPhase', $event)) {
            $debug = is_array($event['suggestionDebug'] ?? null) ? $event['suggestionDebug'] : [];
            $diagnostics = is_array($debug['diagnostics'] ?? null) ? $debug['diagnostics'] : [];
            $seeAllResult = is_array($event['seeAllResult'] ?? null) ? $event['seeAllResult'] : [];
            $suggestionsDialog = is_array($event['suggestionsDialog'] ?? null) ? $event['suggestionsDialog'] : [];
            $surfaceDebug = is_array($event['suggestionsSurfaceDebug'] ?? null) ? $event['suggestionsSurfaceDebug'] : [];
            $progress['suggestionCollectionDebug'] = [
                'type' => ($event['stage'] ?? null) === 'suggestions-scroll-preview' ? 'scroll' : 'collection',
                'phase' => $this->nullableTrim($event['suggestionCollectionPhase'] ?? null),
                'round' => (int) ($event['round'] ?? 0),
                'batchItemsFound' => (int) ($event['batchItemsFound'] ?? 0),
                'profileLinkCandidatesSeen' => (int) ($event['profileLinkCandidatesSeen'] ?? 0),
                'suggestionsObserved' => (int) ($event['suggestionsObserved'] ?? $event['observedSuggestionCount'] ?? 0),
                'headingFound' => (bool) ($debug['headingFound'] ?? false),
                'headingText' => $this->nullableTrim($debug['headingText'] ?? null),
                'anchorScopeFound' => (bool) ($debug['anchorScopeFound'] ?? false),
                'scopedAnchorsSeen' => (int) ($debug['scopedAnchorsSeen'] ?? 0),
                'fallbackAnchorsSeen' => (int) ($debug['fallbackAnchorsSeen'] ?? 0),
                'textFallbackItemsSeen' => (int) ($debug['textFallbackItemsSeen'] ?? 0),
                'anchorsUsed' => (int) ($debug['anchorsUsed'] ?? 0),
                'usernames' => array_values(array_filter(
                    is_array($debug['usernames'] ?? null) ? array_slice($debug['usernames'], 0, 20) : [],
                    'is_scalar',
                )),
                'scrollAdvanced' => (bool) ($event['scrollAdvanced'] ?? false),
                'scrollAtEnd' => (bool) ($event['scrollAtEnd'] ?? false),
                'scrollMode' => $this->nullableTrim($event['scrollMode'] ?? null),
                'rightNavigationVisible' => (bool) ($event['rightNavigationVisible'] ?? false),
                'seeAllClicked' => (bool) ($event['seeAllClicked'] ?? $seeAllResult['clicked'] ?? false),
                'seeAllReason' => $this->nullableTrim($seeAllResult['reason'] ?? null),
                'dialogOpen' => (bool) ($suggestionsDialog['open'] ?? $debug['dialogOpen'] ?? false),
                'dialogProfileLinkCount' => (int) ($suggestionsDialog['profileLinkCount'] ?? 0),
                'dialogTextPreview' => $this->nullableTrim($suggestionsDialog['textPreview'] ?? null),
                'bodyContainsSuggestionText' => (bool) ($diagnostics['bodyContainsSuggestionText'] ?? false),
                'textSamples' => $this->normalizeSuggestionDebugSamples($diagnostics['textSamples'] ?? null, 30),
                'anchorSamples' => $this->normalizeSuggestionDebugSamples($diagnostics['anchorSamples'] ?? null, 30),
                'scopeSamples' => $this->normalizeSuggestionDebugSamples($diagnostics['scopeSamples'] ?? null, 10),
                'surfaceBeforeCollection' => $surfaceDebug ? [
                    'url' => $this->nullableTrim($surfaceDebug['url'] ?? null),
                    'title' => $this->nullableTrim($surfaceDebug['title'] ?? null),
                    'bodyContainsSuggestionText' => (bool) ($surfaceDebug['bodyContainsSuggestionText'] ?? false),
                    'bodyTextPreview' => $this->nullableTrim($surfaceDebug['bodyTextPreview'] ?? null),
                    'profileAnchorUsernames' => array_values(array_filter(
                        is_array($surfaceDebug['profileAnchorUsernames'] ?? null) ? array_slice($surfaceDebug['profileAnchorUsernames'], 0, 60) : [],
                        'is_scalar',
                    )),
                    'seeAllCandidates' => $this->normalizeSuggestionDebugSamples($surfaceDebug['seeAllCandidates'] ?? null, 20),
                    'visibleTextSamples' => $this->normalizeSuggestionDebugSamples($surfaceDebug['visibleTextSamples'] ?? null, 40),
                    'visibleAnchors' => $this->normalizeSuggestionDebugSamples($surfaceDebug['visibleAnchors'] ?? null, 40),
                    'scrollableContainers' => $this->normalizeSuggestionDebugSamples($surfaceDebug['scrollableContainers'] ?? null, 12),
                ] : [],
                'liveScreenshotUrl' => $this->nullableTrim($progress['liveScreenshotUrl'] ?? null),
            ];
        }

        return $progress;
    }

    private function normalizeRelationshipProgress(array $event): array
    {
        if (
            ! in_array((string) ($event['relationship'] ?? ''), ['followers', 'following'], true)
            || ! array_key_exists('itemsPreview', $event)
        ) {
            return [];
        }

        return [
            'relationshipItems' => $this->normalizeConnectionProgressItems($event['itemsPreview'] ?? null, 250),
        ];
    }

    private function normalizeSuggestionDebugSamples(mixed $samples, int $limit): array
    {
        if (! is_array($samples)) {
            return [];
        }

        return collect($samples)
            ->filter(fn ($sample): bool => is_array($sample))
            ->take($limit)
            ->map(function (array $sample): array {
                return collect($sample)
                    ->filter(fn ($value): bool => is_scalar($value) || $value === null)
                    ->map(fn ($value) => is_string($value) ? Str::limit($value, 220, '') : $value)
                    ->all();
            })
            ->values()
            ->all();
    }

    private function normalizeScraperProfileProgress(array $event): array
    {
        $profile = is_array($event['scraperProfile'] ?? null) ? $event['scraperProfile'] : [];
        $label = $this->nullableTrim($event['scraperProfileLabel'] ?? $profile['label'] ?? null);
        $loginUsername = $this->nullableTrim($event['scraperProfileLoginUsername'] ?? $profile['loginUsername'] ?? null);
        $profileId = $this->nullableTrim($event['scraperProfileId'] ?? $profile['id'] ?? null);
        $switchTarget = $this->nullableTrim($event['toProfileLabel'] ?? null);

        return array_filter([
            'scraperProfileLabel' => $label,
            'scraperProfileLoginUsername' => $loginUsername,
            'scraperProfileId' => $profileId,
            'scraperProfileSwitchTarget' => $switchTarget,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeProgressEvent(array $event, string $operationMode, int $progressStart, int $progressEnd): array
    {
        $phase = $event['relationship'] ?? $operationMode;
        $loaded = (int) ($event['loaded'] ?? 0);
        $expected = (int) ($event['expectedCount'] ?? 0);
        $round = (int) ($event['round'] ?? 0);
        $maxRounds = max(1, (int) ($event['maxScrollRounds'] ?? 1));
        $openAttempt = (int) ($event['openAttempt'] ?? 0);
        $stage = (string) ($event['stage'] ?? '');
        $query = (string) ($event['query'] ?? '');
        $phasePercent = match ($stage) {
            'relationship-opening' => 2,
            'relationship-partition-mode' => 3,
            'scan-stop-requested' => 99,
            'relationship-search-opening' => $expected > 0 ? min(98, max(5, (int) floor(($loaded / max(1, $expected)) * 100))) : 55,
            'relationship-search-query-start' => $expected > 0 ? min(98, max(5, (int) floor(($loaded / max(1, $expected)) * 100))) : 60,
            'relationship-search-batch-complete' => $expected > 0 ? min(98, max(5, (int) floor(($loaded / max(1, $expected)) * 100))) : 70,
            'relationship-search-complete' => $expected > 0 && $loaded < $expected ? 98 : 100,
            'relationship-dialog-missing' => 100,
            'relationship-complete' => 100,
            'relationship-rate-limited' => 100,
            'suggestions-opening' => 10,
            'suggestions-scroll-preview' => $expected > 0 ? min(20, max(10, 10 + (int) floor(($loaded / max(1, $expected)) * 10))) : 12,
            'suggestions-see-all-open' => 14,
            'suggestions-target-list' => 20,
            'suggestions-candidate-opening' => $expected > 0 ? min(95, max(20, 20 + (int) floor(($loaded / max(1, $expected)) * 75))) : 35,
            'suggestions-public-list-search' => $expected > 0 ? min(96, max(25, 25 + (int) floor(($loaded / max(1, $expected)) * 75))) : 45,
            'suggestions-candidate-checked' => $expected > 0 ? min(98, max(25, 20 + (int) floor(($loaded / max(1, $expected)) * 78))) : 60,
            'suggestions-candidate-error' => $expected > 0 ? min(98, max(25, 20 + (int) floor(($loaded / max(1, $expected)) * 78))) : 60,
            'suggestions-rate-limited' => 100,
            'suggestions-complete' => 100,
            'posts-opening' => 5,
            'posts-collecting-links' => $expected > 0 ? min(35, max(5, (int) floor(($loaded / max(1, $expected)) * 35))) : 20,
            'posts-opening-item' => $expected > 0 ? min(95, max(35, 35 + (int) floor(($loaded / max(1, $expected)) * 60))) : 50,
            'posts-item-collected' => $expected > 0 ? min(98, max(40, 35 + (int) floor(($loaded / max(1, $expected)) * 63))) : 75,
            'posts-complete' => 100,
            'account-switching' => 8,
            'profile-session-check' => 12,
            'profile-opening' => 25,
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
            'openAttempt' => $openAttempt,
            'query' => $query,
            'message' => $this->nullableTrim($event['message'] ?? null)
                ?: $this->buildProgressMessage($phase, $stage, $loaded, $expected, $openAttempt, $query),
            ...$this->normalizeRelationshipProgress($event),
            ...$this->normalizeLiveScreenshotProgress($event),
            ...$this->normalizeSuggestionProgress($event),
            ...$this->normalizeScraperProfileProgress($event),
        ];
    }

    private function normalizeLiveScreenshotProgress(array $event): array
    {
        $liveScreenshotPath = is_scalar($event['liveScreenshotPath'] ?? null)
            ? trim((string) $event['liveScreenshotPath'])
            : '';

        if ($liveScreenshotPath === '') {
            return [];
        }

        $url = $this->resolvePublicStorageUrl($liveScreenshotPath);

        if (! $url) {
            return [];
        }

        $version = is_scalar($event['liveScreenshotAt'] ?? null)
            ? rawurlencode((string) $event['liveScreenshotAt'])
            : (string) time();

        return [
            'liveScreenshotUrl' => $url.(str_contains($url, '?') ? '&' : '?').'v='.$version,
        ];
    }

    private function buildProgressMessage(string $phase, string $stage, int $loaded, int $expected, int $openAttempt = 0, string $query = ''): string
    {
        if ($stage === 'scan-stop-requested') {
            return 'Stop angefordert. Der aktuelle Zwischestand wird gespeichert.';
        }

        if ($phase === 'followers') {
            if ($stage === 'relationship-rate-limited') {
                return 'Instagram hat die Followerliste per Rate-Limit blockiert; die Listenphase wird abgebrochen.';
            }

            if ($stage === 'account-switching') {
                return 'Rate-Limit erkannt; Scraper-Account wird fuer die Followerliste gewechselt.';
            }

            if ($stage === 'relationship-reopening') {
                return 'Followerliste wird erneut geoeffnet'.($openAttempt > 0 ? ' (Pass '.$openAttempt.')' : '').': '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
            }

            if ($stage === 'relationship-pass-complete') {
                return 'Followerlisten-Pass abgeschlossen: '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
            }

            if (Str::startsWith($stage, 'relationship-search')) {
                return $expected > 0
                    ? 'Followerliste wird per Suche vervollstaendigt'.($query !== '' ? ' ('.$query.')' : '').': '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.')
                    : 'Followerliste wird per Suche vervollstaendigt'.($query !== '' ? ' ('.$query.')' : '').': '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
            }

            return $expected > 0
                ? 'Followerliste wird geladen: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.')
                : 'Followerliste wird geladen: '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
        }

        if ($phase === 'following') {
            if ($stage === 'relationship-rate-limited') {
                return 'Instagram hat die Gefolgt-Liste per Rate-Limit blockiert; die Listenphase wird abgebrochen.';
            }

            if ($stage === 'account-switching') {
                return 'Rate-Limit erkannt; Scraper-Account wird fuer die Gefolgt-Liste gewechselt.';
            }

            if ($stage === 'relationship-reopening') {
                return 'Gefolgt-Liste wird erneut geoeffnet'.($openAttempt > 0 ? ' (Pass '.$openAttempt.')' : '').': '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
            }

            if ($stage === 'relationship-pass-complete') {
                return 'Gefolgt-Listen-Pass abgeschlossen: '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
            }

            if (Str::startsWith($stage, 'relationship-search')) {
                return $expected > 0
                    ? 'Gefolgt-Liste wird per Suche vervollstaendigt'.($query !== '' ? ' ('.$query.')' : '').': '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.')
                    : 'Gefolgt-Liste wird per Suche vervollstaendigt'.($query !== '' ? ' ('.$query.')' : '').': '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
            }

            return $expected > 0
                ? 'Gefolgt-Liste wird geladen: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.')
                : 'Gefolgt-Liste wird geladen: '.number_format($loaded, 0, ',', '.').' Eintraege gefunden';
        }

        if ($phase === 'suggestions') {
            return match ($stage) {
                'suggestions-opening' => 'Profilvorschlaege werden gesucht.',
                'suggestions-see-all-open' => 'Profilvorschlagsliste wird direkt geoeffnet.',
                'suggestions-target-list' => 'Profilvorschlaege gefunden; Kandidatenpruefung startet.',
                'suggestions-candidate-opening' => 'Vorschlaege eines Kandidaten werden geoeffnet: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.'),
                'suggestions-public-list-search' => 'Oeffentliche Listen eines Vorschlags werden durchsucht: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.'),
                'suggestions-candidate-checked' => 'Vorschlags-Kandidaten geprueft: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.'),
                'suggestions-candidate-error' => 'Ein Vorschlags-Kandidat konnte nicht geprueft werden: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.'),
                'suggestions-rate-limited' => 'Instagram hat die Profilvorschlaege per Rate-Limit blockiert.',
                'suggestions-complete' => 'Vorschlaege-Scan abgeschlossen.',
                default => $expected > 0
                    ? 'Profilvorschlaege werden geprueft: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.')
                    : 'Profilvorschlaege werden geprueft.',
            };
        }

        if ($phase === 'posts') {
            return match ($stage) {
                'posts-opening' => 'Instagram-Beitraege werden gesucht.',
                'posts-collecting-links' => number_format($loaded, 0, ',', '.').' Beitragslinks gefunden.',
                'posts-opening-item' => 'Beitrag wird geoeffnet: '.number_format($loaded + 1, 0, ',', '.').' von '.number_format($expected, 0, ',', '.'),
                'posts-item-collected' => 'Beitraege geprueft: '.number_format($loaded, 0, ',', '.').' von '.number_format($expected, 0, ',', '.'),
                'posts-complete' => 'Instagram-Beitragsscan abgeschlossen.',
                default => 'Instagram-Beitraege werden geprueft.',
            };
        }

        return match ($stage) {
            'profile-session-check' => 'Instagram-Session wird geprueft.',
            'profile-opening' => 'Instagram-Profilseite wird geoeffnet.',
            'profile-page-loaded' => 'Profilseite geladen, Grunddaten werden ausgelesen.',
            'profile-collected' => 'Grunddaten wurden ausgelesen.',
            default => 'Instagram-Grunddaten werden geladen.',
        };
    }

    private function normalizeOperationMode(string $operationMode): string
    {
        $operationMode = Str::lower(trim($operationMode));

        return in_array($operationMode, ['analyze', 'mini', 'profile', 'followers', 'following', 'suggestions', 'suggestion-connections', 'posts', 'login-session'], true)
            ? $operationMode
            : 'analyze';
    }

    private function resolveNodeScriptForOperationMode(string $operationMode): string
    {
        $scriptName = match ($operationMode) {
            'mini' => 'scrape-instagram-mini.cjs',
            'analyze', 'profile' => 'scrape-instagram-full.cjs',
            'followers', 'following' => 'scrape-instagram-list.cjs',
            'suggestions', 'suggestion-connections' => 'scrape-instagram-suggestions.cjs',
            'posts' => 'scrape-instagram-posts.cjs',
            default => 'scrape-instagram.cjs',
        };

        return base_path('resources/node/scraper/'.$scriptName);
    }

    private function writeRuntimeConfig(array $overrides = []): string
    {
        $directory = storage_path('app/tmp');
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.'instagram-scraper-config-'.Str::uuid().'.json';
        $runtimeConfig = [
            ...$this->buildRuntimeConfig(),
            ...$overrides,
        ];

        app(ScraperProfileDatabaseStore::class)->hydrateCookieFilesFromRuntimeConfig($runtimeConfig);
        File::put($path, json_encode($runtimeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function buildRuntimeConfig(): array
    {
        $settings = $this->loadScraperProfileSettings();
        $profile = $this->resolveActiveScraperProfile($settings);
        $runtimePassword = $this->resolveRuntimePassword($profile);

        return [
            'profileId' => trim((string) ($profile['id'] ?? '')),
            'profileLabel' => (string) ($profile['profile_label'] ?? 'instagram-default'),
            // Reguläre Analysen verwenden ein frisches Browser-Profil und laden die Session aus der Cookie-Datei.
            // Ein geteiltes persistentes Chrome-Profil blockiert bei parallelen Scans schnell DevTools/Puppeteer.
            'persistentProfileEnabled' => false,
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
            // Softes Wartefenster fuer einzelne Instagram-Navigationen; der PHP-Prozess selbst laeuft ohne Timeout.
            'navigationTimeoutMs' => max(30000, ((int) ($profile['navigation_timeout_seconds'] ?? 120)) * 1000),
            'postLoginWaitMs' => max(500, (int) ($profile['post_login_wait_ms'] ?? 2500)),
            'typingDelayMs' => max(0, (int) ($profile['typing_delay_ms'] ?? 35)),
            'followerListMaxItems' => max(0, (int) ($profile['follower_list_max_items'] ?? 0)),
            'followingListMaxItems' => max(0, (int) ($profile['following_list_max_items'] ?? 0)),
            'relationshipListMaxScrollRounds' => max(20, (int) ($profile['relationship_list_max_scroll_rounds'] ?? 100000)),
            'accountPool' => $this->buildRuntimeAccountPool($settings, $profile),
            'creditCosts' => $this->loadCreditCostSettings(),
        ];
    }

    private function loadCreditCostSettings(): array
    {
        $settings = Setting::getValue('billing', 'credit_costs');

        return is_array($settings) ? $settings : [];
    }

    private function loadScraperProfileSettings(): mixed
    {
        $settings = Setting::getValue('scraper', 'instagram_profile');
        $databaseStore = app(ScraperProfileDatabaseStore::class);

        if (! $databaseStore->isAvailable()) {
            return $settings;
        }

        if (is_array($settings)) {
            $databaseStore->importLegacyCollectionIfMissing($settings, storage_path('app'));
        }

        $databaseCollection = $databaseStore->loadProfileCollection(is_array($settings) ? $settings : null);

        if (is_array($databaseCollection)) {
            $databaseStore->hydrateCookieFilesFromCollection($databaseCollection, storage_path('app'));

            return $databaseCollection;
        }

        return $settings;
    }

    private function syncScraperProfileCookiesFromRuntimeConfig(?string $runtimeConfigPath): void
    {
        try {
            app(ScraperProfileDatabaseStore::class)->syncCookiePayloadsFromRuntimeConfigFile($runtimeConfigPath);
        } catch (\Throwable) {
            // Cookie-Sync darf den eigentlichen Scrape nicht ueberdecken.
        }
    }

    private function buildRuntimeAccountPool(mixed $settings, array $selectedProfile): array
    {
        $profiles = $this->resolveScraperProfiles($settings);

        if ($profiles === []) {
            return [$this->buildRuntimeAccountConfig($selectedProfile)];
        }

        $activeProfileIds = $this->normalizeActiveProfileIds(
            is_array($settings) ? ($settings['active_profile_ids'] ?? null) : null,
        );
        $activeProfiles = $activeProfileIds !== []
            ? array_values(array_filter(
                $profiles,
                static fn (array $profile): bool => in_array(trim((string) ($profile['id'] ?? '')), $activeProfileIds, true),
            ))
            : [];

        if ($activeProfiles === []) {
            $activeProfileId = trim((string) (is_array($settings) ? ($settings['active_profile_id'] ?? '') : ''));

            if ($activeProfileId !== '') {
                $activeProfiles = array_values(array_filter(
                    $profiles,
                    static fn (array $profile): bool => trim((string) ($profile['id'] ?? '')) === $activeProfileId,
                ));
            }
        }

        if ($activeProfiles === []) {
            $activeProfiles = [$selectedProfile];
        }

        $pool = [];

        foreach ([$selectedProfile, ...$activeProfiles] as $profile) {
            if ($this->profileIsScrapeBlocked($profile)) {
                continue;
            }

            $key = $this->runtimeProfileKey($profile);

            if ($key === '' || isset($pool[$key])) {
                continue;
            }

            $pool[$key] = $this->buildRuntimeAccountConfig($profile);
        }

        if ($pool !== []) {
            return array_values($pool);
        }

        throw new \RuntimeException(
            'Alle aktiven Instagram-Scraper-Accounts sind aktuell wegen Instagram-Rate-Limit gesperrt.'
            .$this->nextScrapeBlockReleaseSuffix($activeProfiles)
        );
    }

    private function buildRuntimeAccountConfig(array $profile): array
    {
        $runtimePassword = $this->resolveRuntimePassword($profile);

        return [
            'profileId' => trim((string) ($profile['id'] ?? '')),
            'profileLabel' => (string) ($profile['profile_label'] ?? 'instagram-default'),
            'persistentProfileEnabled' => false,
            'browserProfilePath' => $this->resolveStorageAwarePath($profile['browser_profile_path'] ?? 'browser-profiles/instagram/default'),
            'cookieFilePath' => $this->resolveStorageAwarePath($profile['cookie_file_path'] ?? 'cookies/instagram-cookies.json'),
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

    private function resolveScraperProfiles(mixed $settings): array
    {
        if (! is_array($settings)) {
            return [];
        }

        if (! isset($settings['profiles']) || ! is_array($settings['profiles'])) {
            return $settings === [] ? [] : [$settings];
        }

        $profiles = [];

        foreach ($settings['profiles'] as $key => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            if (! isset($profile['id']) && is_string($key)) {
                $profile['id'] = $key;
            }

            $profiles[] = $profile;
        }

        return $profiles;
    }

    private function runtimeProfileKey(array $profile): string
    {
        foreach (['id', 'cookie_file_path', 'login_username', 'profile_label'] as $field) {
            $value = trim((string) ($profile[$field] ?? ''));

            if ($value !== '') {
                return $field.':'.$value;
            }
        }

        return '';
    }

    private function resolveActiveScraperProfile(mixed $settings): array
    {
        $profiles = $this->resolveScraperProfiles($settings);

        if ($profiles === []) {
            return [];
        }

        $activeProfileIds = $this->normalizeActiveProfileIds(
            is_array($settings) ? ($settings['active_profile_ids'] ?? null) : null,
        );
        $activeProfiles = array_values(array_filter(
            $profiles,
            static fn (array $profile): bool => in_array(trim((string) ($profile['id'] ?? '')), $activeProfileIds, true),
        ));
        $availableActiveProfiles = $this->filterScrapeAvailableProfiles($activeProfiles);

        if ($availableActiveProfiles !== []) {
            return $availableActiveProfiles[random_int(0, count($availableActiveProfiles) - 1)];
        }

        $activeProfileId = trim((string) (is_array($settings) ? ($settings['active_profile_id'] ?? '') : ''));

        foreach ($profiles as $profile) {
            if (
                trim((string) ($profile['id'] ?? '')) === $activeProfileId
                && ! $this->profileIsScrapeBlocked($profile)
            ) {
                return $profile;
            }
        }

        if ($activeProfiles !== []) {
            throw new \RuntimeException(
                'Alle aktiven Instagram-Scraper-Accounts sind aktuell wegen Instagram-Rate-Limit gesperrt.'
                .$this->nextScrapeBlockReleaseSuffix($activeProfiles)
            );
        }

        $availableProfiles = $this->filterScrapeAvailableProfiles($profiles);

        if ($availableProfiles !== []) {
            return $availableProfiles[0];
        }

        throw new \RuntimeException(
            'Alle Instagram-Scraper-Accounts sind aktuell wegen Instagram-Rate-Limit gesperrt.'
            .$this->nextScrapeBlockReleaseSuffix($profiles)
        );
    }

    private function filterScrapeAvailableProfiles(array $profiles): array
    {
        return array_values(array_filter(
            $profiles,
            fn (array $profile): bool => ! $this->profileIsScrapeBlocked($profile),
        ));
    }

    private function profileIsScrapeBlocked(array $profile): bool
    {
        $blockedUntil = $profile['scrape_blocked_until'] ?? null;

        if (! is_scalar($blockedUntil) || trim((string) $blockedUntil) === '') {
            return false;
        }

        try {
            return Carbon::parse((string) $blockedUntil)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    private function nextScrapeBlockReleaseSuffix(array $profiles): string
    {
        $timestamps = [];

        foreach ($profiles as $profile) {
            $blockedUntil = $profile['scrape_blocked_until'] ?? null;

            if (! is_scalar($blockedUntil) || trim((string) $blockedUntil) === '') {
                continue;
            }

            try {
                $timestamp = Carbon::parse((string) $blockedUntil);

                if ($timestamp->isFuture()) {
                    $timestamps[] = $timestamp;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($timestamps === []) {
            return '';
        }

        usort($timestamps, static fn (Carbon $left, Carbon $right): int => $left->getTimestamp() <=> $right->getTimestamp());

        return ' Naechste Freigabe: '
            .$timestamps[0]->timezone(config('app.timezone', 'Europe/Berlin'))->format('d.m.Y H:i')
            .'.';
    }

    private function normalizeActiveProfileIds(mixed $activeProfileIds): array
    {
        if (! is_array($activeProfileIds)) {
            return [];
        }

        $normalizedIds = [];

        foreach ($activeProfileIds as $activeProfileId) {
            $activeProfileId = trim((string) $activeProfileId);

            if ($activeProfileId === '') {
                continue;
            }

            $normalizedIds[] = $activeProfileId;
        }

        return array_values(array_unique($normalizedIds));
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
        $environmentCandidates = array_filter([
            config('services.node.binary'),
            env('SCRAPER_NODE_BINARY'),
            env('NODE_BINARY'),
            getenv('SCRAPER_NODE_BINARY') ?: null,
            getenv('NODE_BINARY') ?: null,
        ], static fn (mixed $candidate): bool => is_string($candidate) && trim($candidate) !== '');

        $candidates = array_map(
            static fn (string $candidate): string => trim($candidate, " \t\n\r\0\x0B\"'"),
            $environmentCandidates,
        );

        $candidates = array_merge($candidates, PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\\Program Files\\nodejs\\node.exe',
                'C:\\Program Files (x86)\\nodejs\\node.exe',
            ]
            : [
                '/usr/bin/node',
                '/usr/local/bin/node',
                '/bin/node',
                '/snap/bin/node',
                '/usr/bin/nodejs',
                '/usr/local/bin/nodejs',
            ]);

        foreach (glob('/opt/plesk/node/*/bin/node') ?: [] as $pleskCandidate) {
            $candidates[] = $pleskCandidate;
        }

        $homeDirectory = getenv('HOME') ?: null;

        if (is_string($homeDirectory) && trim($homeDirectory) !== '') {
            foreach (glob($homeDirectory.'/.nvm/versions/node/*/bin/node') ?: [] as $nvmCandidate) {
                $candidates[] = $nvmCandidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '' && File::exists($candidate)) {
                return $candidate;
            }
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            foreach (['node', 'nodejs'] as $binaryName) {
                $resolvedBinary = Process::run(['sh', '-lc', sprintf('command -v %s 2>/dev/null', $binaryName)]);

                if (! $resolvedBinary->successful()) {
                    continue;
                }

                $candidate = trim($resolvedBinary->output());

                if ($candidate !== '' && File::exists($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new \RuntimeException(
            PHP_OS_FAMILY === 'Windows'
                ? 'Node.js wurde nicht gefunden. Bitte in der .env z. B. NODE_BINARY="C:\\Program Files\\nodejs\\node.exe" setzen.'
                : 'Node.js wurde nicht gefunden. Bitte in der .env z. B. NODE_BINARY="/usr/bin/node" oder SCRAPER_NODE_BINARY="/opt/plesk/node/<version>/bin/node" setzen.'
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
