<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class InstagramProfileDataExtractor
{
    public function extract(array $payload): array
    {
        $html = $this->loadHtml($payload);
        $description = (string) data_get($payload, 'profile.description', '');
        $ogTitle = (string) data_get($payload, 'profile.ogTitle', '');
        $bodyTextPreview = (string) data_get($payload, 'profile.bodyTextPreview', '');
        $profileImageUrl = $this->firstFilledPayloadValue($payload, [
            'profile.profileImageUrl',
            'profile.profile_image_url',
            'profile.imageUrl',
            'profile.avatarUrl',
            'profile.ogImage',
            'profileImageUrl',
            'profile_image_url',
            'ogImage',
        ]);
        $operationMode = Str::lower((string) ($payload['operationMode'] ?? ''));
        $counts = $this->extractCounts([
            'body_text_preview' => $bodyTextPreview,
            'description_meta' => $description,
            'html_document' => $html,
            'profile_counts' => data_get($payload, 'profile.counts', []),
        ], in_array($operationMode, ['mini', 'mini-scan', 'public', 'public-profile'], true));
        $privacy = $this->extractPrivacyStatus($payload, $html, $bodyTextPreview, $description, $ogTitle);

        return [
            'full_name' => $this->extractFullName($ogTitle),
            'biography' => $this->extractBiography($description),
            'is_private' => $privacy['is_private'],
            'profile_visibility' => $privacy['visibility'],
            'posts_count' => $counts['posts'],
            'followers_count' => $counts['followers'],
            'following_count' => $counts['following'],
            'count_sources' => $counts['sources'],
            'count_warnings' => $counts['warnings'],
            'visible_counts_complete' => $counts['visible_complete'],
            'profile_image_url' => $profileImageUrl,
            'image_urls' => $this->extractImageUrls($html, $profileImageUrl),
            'story_scan' => is_array(data_get($payload, 'profile.storyScan'))
                ? data_get($payload, 'profile.storyScan')
                : (is_array($payload['storyScan'] ?? null) ? $payload['storyScan'] : []),
            'followers_list' => $this->extractFollowersList($payload),
            'following_list' => $this->extractFollowingList($payload),
        ];
    }

    private function extractPrivacyStatus(
        array $payload,
        string $html,
        string $bodyTextPreview,
        string $description,
        string $ogTitle,
    ): array {
        $rawIsPrivate = data_get($payload, 'profile.isPrivate');
        $requiresLogin = (bool) data_get($payload, 'profile.requiresLogin', false);
        $usernameSeen = (bool) data_get($payload, 'profile.usernameSeen', false);
        $scrapeOk = (bool) ($payload['ok'] ?? false);
        $combinedText = $this->normalizeSourceText($bodyTextPreview.' '.$description.' '.$ogTitle.' '.strip_tags($html));
        $privatePattern = '/dieses profil ist privat|this account is private|private account|privates profil/i';

        if ($rawIsPrivate === true || preg_match($privatePattern, $combinedText)) {
            return [
                'is_private' => true,
                'visibility' => 'private',
            ];
        }

        if ($rawIsPrivate === false && ($usernameSeen || ($scrapeOk && ! $requiresLogin))) {
            return [
                'is_private' => false,
                'visibility' => 'public',
            ];
        }

        return [
            'is_private' => null,
            'visibility' => 'unknown',
        ];
    }

    private function extractFollowersList(array $payload): array
    {
        return $this->extractRelationshipList($payload, 'followersList');
    }

    private function extractFollowingList(array $payload): array
    {
        return $this->extractRelationshipList($payload, 'followingList');
    }

    private function extractRelationshipList(array $payload, string $payloadKey): array
    {
        $relationshipList = data_get($payload, 'profile.'.$payloadKey, []);
        $items = collect(data_get($relationshipList, 'items', []))
            ->filter(fn ($item) => is_array($item) && filled($item['username'] ?? null))
            ->map(fn ($item) => [
                'username' => Str::lower(trim((string) ($item['username'] ?? ''))),
                'displayName' => filled($item['displayName'] ?? null) ? trim((string) $item['displayName']) : null,
                'profileUrl' => filled($item['profileUrl'] ?? null) ? (string) $item['profileUrl'] : null,
                'profileImageUrl' => filled($item['profileImageUrl'] ?? $item['profile_image_url'] ?? null)
                    ? (string) ($item['profileImageUrl'] ?? $item['profile_image_url'])
                    : null,
                'profileVisibility' => in_array(($item['profileVisibility'] ?? null), ['public', 'private', 'unknown'], true)
                    ? $item['profileVisibility']
                    : null,
                'isPrivate' => is_bool($item['isPrivate'] ?? null) ? $item['isPrivate'] : null,
                'postsCount' => is_numeric($item['postsCount'] ?? null) ? (int) $item['postsCount'] : null,
                'followersCount' => is_numeric($item['followersCount'] ?? null) ? (int) $item['followersCount'] : null,
                'followingCount' => is_numeric($item['followingCount'] ?? null) ? (int) $item['followingCount'] : null,
                'hoverCard' => is_array($item['hoverCard'] ?? null) ? $item['hoverCard'] : null,
            ])
            ->unique('username')
            ->values()
            ->all();

        return [
            'attempted' => (bool) data_get($relationshipList, 'attempted', false),
            'available' => (bool) data_get($relationshipList, 'available', false),
            'complete' => (bool) data_get($relationshipList, 'complete', false),
            'count' => count($items),
            'maxItems' => (int) data_get($relationshipList, 'maxItems', 0),
            'expectedCount' => (int) data_get($relationshipList, 'expectedCount', 0),
            'openAttempts' => (int) data_get($relationshipList, 'openAttempts', 0),
            'scrollRounds' => (int) data_get($relationshipList, 'scrollRounds', 0),
            'noProgressReopenLimit' => (int) data_get($relationshipList, 'noProgressReopenLimit', 0),
            'reason' => data_get($relationshipList, 'reason'),
            'rateLimited' => (bool) data_get($relationshipList, 'rateLimited', false),
            'rateLimitText' => data_get($relationshipList, 'rateLimitText'),
            'listTemporarilyUnavailable' => (bool) data_get($relationshipList, 'listTemporarilyUnavailable', false),
            'gracefullyStopped' => (bool) data_get($relationshipList, 'gracefullyStopped', false),
            'searchAttempted' => (bool) data_get($relationshipList, 'searchAttempted', false),
            'searchInputAvailable' => (bool) data_get($relationshipList, 'searchInputAvailable', false),
            'searchQueries' => array_values(is_array(data_get($relationshipList, 'searchQueries')) ? data_get($relationshipList, 'searchQueries') : []),
            'searchRounds' => (int) data_get($relationshipList, 'searchRounds', 0),
            'searchAddedCount' => (int) data_get($relationshipList, 'searchAddedCount', 0),
            'searchStopReason' => data_get($relationshipList, 'searchStopReason'),
            'searchMaxDepth' => (int) data_get($relationshipList, 'searchMaxDepth', 0),
            'searchExpandedQueryCount' => (int) data_get($relationshipList, 'searchExpandedQueryCount', 0),
            'verifiedMissingUsernames' => array_values(array_filter(
                is_array(data_get($relationshipList, 'verifiedMissingUsernames'))
                    ? data_get($relationshipList, 'verifiedMissingUsernames')
                    : [],
                'is_scalar',
            )),
            'verifiedPresentUsernames' => array_values(array_filter(
                is_array(data_get($relationshipList, 'verifiedPresentUsernames'))
                    ? data_get($relationshipList, 'verifiedPresentUsernames')
                    : [],
                'is_scalar',
            )),
            'partitioned' => (bool) data_get($relationshipList, 'partitioned', false),
            'partitionThreshold' => (int) data_get($relationshipList, 'partitionThreshold', 250),
            'partitionMaxItems' => (int) data_get($relationshipList, 'partitionMaxItems', 250),
            'items' => $items,
        ];
    }

    private function loadHtml(array $payload): string
    {
        $htmlPath = data_get($payload, 'htmlPath');

        if (is_string($htmlPath) && $htmlPath !== '' && File::exists($htmlPath)) {
            return File::get($htmlPath);
        }

        return (string) ($payload['htmlPreview'] ?? '');
    }

    private function firstFilledPayloadValue(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function extractCounts(array $texts, bool $allowFallbackCounts = false): array
    {
        $visibleCounts = $this->extractCountsFromSource($texts['body_text_preview'] ?? null);
        $profileCounts = $this->extractCountsFromProfilePayload($texts['profile_counts'] ?? []);
        $metaCounts = $allowFallbackCounts
            ? $this->emptyCounts()
            : $this->extractCountsFromSource($texts['description_meta'] ?? null);
        $htmlBodyCounts = $this->extractCountsFromHtmlDocument($texts['html_document'] ?? null);
        $htmlProfileDataCounts = $this->extractCountsFromEmbeddedProfileData($texts['html_document'] ?? null);
        $htmlCounts = $this->mergeCountSets($htmlBodyCounts, $htmlProfileDataCounts);
        $selectedCounts = $visibleCounts;
        $sources = collect($selectedCounts)
            ->map(fn ($value) => $value !== null ? 'body_text_preview' : null)
            ->all();

        if ($allowFallbackCounts) {
            foreach (array_keys($selectedCounts) as $metric) {
                if ($selectedCounts[$metric] !== null) {
                    continue;
                }

                if (($profileCounts['values'][$metric] ?? null) !== null) {
                    $selectedCounts[$metric] = $profileCounts['values'][$metric];
                    $sources[$metric] = $profileCounts['sources'][$metric] ?? 'profile_dom';

                    continue;
                }

                if (($htmlProfileDataCounts[$metric] ?? null) !== null) {
                    $selectedCounts[$metric] = $htmlProfileDataCounts[$metric];
                    $sources[$metric] = 'html_profile_data';

                    continue;
                }

                if (($htmlCounts[$metric] ?? null) !== null) {
                    $selectedCounts[$metric] = $htmlCounts[$metric];
                    $sources[$metric] = 'html_document';
                }
            }
        }

        return [
            'posts' => $selectedCounts['posts'],
            'followers' => $selectedCounts['followers'],
            'following' => $selectedCounts['following'],
            'sources' => $sources,
            'warnings' => $this->buildCountWarnings($visibleCounts, $metaCounts, $htmlCounts, $allowFallbackCounts, $sources),
            'visible_complete' => $selectedCounts['posts'] !== null
                && $selectedCounts['followers'] !== null
                && $selectedCounts['following'] !== null,
        ];
    }

    private function extractCountsFromSource(?string $text): array
    {
        $normalizedText = $this->normalizeSourceText($text);
        $patterns = [
            'posts' => [
                '/([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:Beitr(?:ag|aege|äge)|Posts?)/iu',
            ],
            'followers' => [
                '/([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:Follower|Followers)/iu',
            ],
            'following' => [
                '/([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:Gefolgt|Following)/iu',
            ],
        ];
        $values = [
            'posts' => null,
            'followers' => null,
            'following' => null,
        ];

        if ($normalizedText === '') {
            return $values;
        }

        foreach ($patterns as $metric => $metricPatterns) {
            $values[$metric] = $this->extractCountByPatterns($normalizedText, $metricPatterns);
        }

        return $values;
    }

    private function extractCountsFromHtmlDocument(?string $html): array
    {
        if (! is_string($html) || trim($html) === '') {
            return $this->extractCountsFromSource(null);
        }

        $body = $html;

        if (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $body = $matches[1];
        } else {
            $body = preg_replace('/<head\b[^>]*>.*?<\/head>/is', ' ', $body) ?? $body;
        }

        $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $body) ?? $body;
        $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $body) ?? $body;
        $body = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $body) ?? $body;
        $body = preg_replace('/<meta\b[^>]*>/i', ' ', $body) ?? $body;
        $body = strip_tags($body);

        return $this->extractCountsFromSource($body);
    }

    private function extractCountsFromProfilePayload(mixed $payloadCounts): array
    {
        $values = $this->emptyCounts();
        $sources = [
            'posts' => null,
            'followers' => null,
            'following' => null,
        ];

        if (! is_array($payloadCounts)) {
            return [
                'values' => $values,
                'sources' => $sources,
            ];
        }

        foreach (array_keys($values) as $metric) {
            $value = $payloadCounts[$metric] ?? null;

            if (! is_numeric($value)) {
                continue;
            }

            $source = data_get($payloadCounts, 'sources.'.$metric);

            if ($source === 'description_meta') {
                continue;
            }

            $values[$metric] = (int) $value;
            $sources[$metric] = is_string($source) && trim($source) !== ''
                ? trim($source)
                : 'profile_dom';
        }

        return [
            'values' => $values,
            'sources' => $sources,
        ];
    }

    private function extractCountsFromEmbeddedProfileData(?string $html): array
    {
        $values = $this->emptyCounts();

        if (! is_string($html) || trim($html) === '') {
            return $values;
        }

        $normalizedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $htmlVariants = array_values(array_unique(array_filter([
            $normalizedHtml,
            stripcslashes($normalizedHtml),
            str_replace(['\\"', '\\u0022'], '"', $normalizedHtml),
            stripcslashes(str_replace(['\\"', '\\u0022'], '"', $normalizedHtml)),
        ], static fn (string $variant): bool => trim($variant) !== '')));
        $patterns = [
            'posts' => [
                '/"edge_owner_to_timeline_media"\s*:\s*\{\s*"count"\s*:\s*(\d+)/i',
                '/"media_count"\s*:\s*(\d+)/i',
                '/"profile_media_count"\s*:\s*(\d+)/i',
            ],
            'followers' => [
                '/"edge_followed_by"\s*:\s*\{\s*"count"\s*:\s*(\d+)/i',
                '/"edge_followed_by"\\?\s*:\\?\s*\{\\?\s*"count"\\?\s*:\\?\s*(\d+)/i',
                '/"follower_count"\s*:\s*(\d+)/i',
                '/"followers_count"\s*:\s*(\d+)/i',
                '/"followerCount"\s*:\s*(\d+)/i',
                '/"followersCount"\s*:\s*(\d+)/i',
                '/"followers"\s*:\s*\{\s*"count"\s*:\s*(\d+)/i',
            ],
            'following' => [
                '/"edge_follow"\s*:\s*\{\s*"count"\s*:\s*(\d+)/i',
                '/"edge_follow"\\?\s*:\\?\s*\{\\?\s*"count"\\?\s*:\\?\s*(\d+)/i',
                '/"following_count"\s*:\s*(\d+)/i',
                '/"follows_count"\s*:\s*(\d+)/i',
                '/"followingCount"\s*:\s*(\d+)/i',
                '/"followsCount"\s*:\s*(\d+)/i',
                '/"following"\s*:\s*\{\s*"count"\s*:\s*(\d+)/i',
            ],
        ];

        foreach ($htmlVariants as $htmlVariant) {
            foreach ($patterns as $metric => $metricPatterns) {
                if ($values[$metric] !== null) {
                    continue;
                }

                foreach ($metricPatterns as $pattern) {
                    if (! preg_match($pattern, $htmlVariant, $matches)) {
                        continue;
                    }

                    $value = (int) ($matches[1] ?? 0);

                    if ($value >= 0) {
                        $values[$metric] = $value;
                        break;
                    }
                }
            }
        }

        return $values;
    }

    private function emptyCounts(): array
    {
        return [
            'posts' => null,
            'followers' => null,
            'following' => null,
        ];
    }

    private function mergeCountSets(array ...$sets): array
    {
        $merged = $this->emptyCounts();

        foreach (array_keys($merged) as $metric) {
            foreach ($sets as $set) {
                if (($set[$metric] ?? null) === null) {
                    continue;
                }

                $merged[$metric] = $set[$metric];
                break;
            }
        }

        return $merged;
    }

    private function normalizeSourceText(?string $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function buildCountWarnings(
        array $visibleCounts,
        array $metaCounts,
        array $htmlCounts,
        bool $allowFallbackCounts = false,
        array $selectedSources = [],
    ): array {
        $warnings = [];
        $metricLabels = [
            'posts' => 'Beitragszahl',
            'followers' => 'Follower-Zahl',
            'following' => 'Gefolgt-Zahl',
        ];

        foreach (array_keys($metricLabels) as $metric) {
            $visibleValue = $visibleCounts[$metric] ?? null;
            $metaValue = $metaCounts[$metric] ?? null;
            $htmlValue = $htmlCounts[$metric] ?? null;

            if ($visibleValue !== null && $metaValue !== null && $visibleValue !== $metaValue) {
                $warnings[] = sprintf(
                    '%s weicht zwischen sichtbarem Profiltext (%s) und Meta-Beschreibung (%s) ab; gespeichert wird nur der sichtbare Wert.',
                    $metricLabels[$metric],
                    $visibleValue,
                    $metaValue,
                );
            }

            if ($visibleValue !== null && $htmlValue !== null && $visibleValue !== $htmlValue) {
                $warnings[] = sprintf(
                    '%s weicht zwischen sichtbarem Profiltext (%s) und HTML-Fallback (%s) ab; gespeichert wird nur der sichtbare Wert.',
                    $metricLabels[$metric],
                    $visibleValue,
                    $htmlValue,
                );
            }
        }

        $hasVisibleCounts = collect($visibleCounts)->contains(fn ($value) => $value !== null);
        $hasFallbackCounts = $allowFallbackCounts
            ? collect($selectedSources)->contains(fn ($source) => in_array($source, ['description_meta', 'html_document', 'html_profile_data'], true))
            : (
                collect($metaCounts)->contains(fn ($value) => $value !== null)
                || collect($htmlCounts)->contains(fn ($value) => $value !== null)
            );

        if (! $hasVisibleCounts && $hasFallbackCounts) {
            $warnings[] = $allowFallbackCounts
                ? 'Mini-Scan hat keine sichtbaren Kennzahlen gefunden; gespeicherte Zahlen stammen ausschliesslich aus oeffentlichen Profil- oder HTML-Daten. Meta-Daten werden im Mini-Scan nicht als Zaehlwerte verwendet.'
                : 'Instagram hat keine sichtbaren Kennzahlen geliefert; Meta- und HTML-Werte werden bewusst nicht als offizielle Zahlen gespeichert.';
        }

        if ($allowFallbackCounts) {
            foreach ($metricLabels as $metric => $label) {
                $source = $selectedSources[$metric] ?? null;

                if (in_array($source, ['description_meta', 'html_document', 'html_profile_data', 'profile_dom', 'body_text_preview'], true)) {
                    $sourceLabel = match ($source) {
                        'description_meta' => 'der Meta-Beschreibung',
                        'profile_dom' => 'dem sichtbaren Profil-DOM',
                        'body_text_preview' => 'dem sichtbaren Profiltext',
                        'html_profile_data' => 'eingebetteten Profil-Daten im HTML',
                        default => 'dem HTML-Fallback',
                    };
                    $warnings[] = $label.' wurde im Mini-Scan aus '.$sourceLabel.' gelesen.';
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    private function extractCountByPatterns(string $text, array $patterns): ?int
    {
        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            return $this->normalizeCountValue($matches[1] ?? null);
        }

        return null;
    }

    private function normalizeCountValue(?string $rawValue): ?int
    {
        $rawValue = trim((string) $rawValue);

        if ($rawValue === '') {
            return null;
        }

        $multiplier = 1;
        $normalizedSuffixValue = Str::lower($rawValue);

        if (preg_match('/\bmio\b/u', $normalizedSuffixValue)) {
            $multiplier = 1000000;
        } elseif (preg_match('/\bm\b/u', $normalizedSuffixValue)) {
            $multiplier = 1000000;
        } elseif (preg_match('/\bk\b/u', $normalizedSuffixValue) || preg_match('/\btsd\b/u', $normalizedSuffixValue)) {
            $multiplier = 1000;
        }

        $numericPart = preg_replace('/[^\d.,]/u', '', $rawValue);

        if ($numericPart === '') {
            return null;
        }

        if ($multiplier === 1) {
            $digits = preg_replace('/[^\d]/', '', $numericPart);

            return $digits !== '' ? (int) $digits : null;
        }

        $decimalValue = str_replace(',', '.', preg_replace('/\s+/', '', $numericPart));

        if (! is_numeric($decimalValue)) {
            return null;
        }

        return (int) round(((float) $decimalValue) * $multiplier);
    }

    private function extractFullName(string $ogTitle): ?string
    {
        $ogTitle = trim(html_entity_decode($ogTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($ogTitle === '') {
            return null;
        }

        if (preg_match('/^(.*?)\s*\(@/u', $ogTitle, $matches)) {
            $fullName = trim($matches[1]);

            return $fullName !== '' ? $fullName : null;
        }

        return null;
    }

    private function extractBiography(string $description): ?string
    {
        $description = trim(html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($description === '') {
            return null;
        }

        if (preg_match('/Instagram:\s*[„"](.+?)[“"]/u', $description, $matches)) {
            $biography = trim($matches[1]);

            return $biography !== '' ? $biography : null;
        }

        if (preg_match('/on Instagram:\s*[“"](.+?)[”"]/u', $description, $matches)) {
            $biography = trim($matches[1]);

            return $biography !== '' ? $biography : null;
        }

        return null;
    }

    private function extractImageUrls(string $html, ?string $profileImageUrl): array
    {
        if (! $profileImageUrl) {
            return [];
        }

        $decodedUrl = html_entity_decode((string) $profileImageUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decodedUrl = str_replace(['\\/', '\u0026', '&amp;'], ['/', '&', '&'], $decodedUrl);

        if (! Str::startsWith($decodedUrl, 'http')) {
            return [];
        }

        // Nur das eindeutig dem Zielprofil zuordenbare Profilbild speichern.
        return [$decodedUrl];
    }
}
