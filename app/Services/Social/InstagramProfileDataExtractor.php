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
        $profileImageUrl = data_get($payload, 'profile.ogImage');
        $counts = $this->extractCounts([
            'body_text_preview' => $bodyTextPreview,
            'description_meta' => $description,
            'html_document' => $html,
        ]);

        return [
            'full_name' => $this->extractFullName($ogTitle),
            'biography' => $this->extractBiography($description),
            'posts_count' => $counts['posts'],
            'followers_count' => $counts['followers'],
            'following_count' => $counts['following'],
            'count_sources' => $counts['sources'],
            'count_warnings' => $counts['warnings'],
            'visible_counts_complete' => $counts['visible_complete'],
            'profile_image_url' => $profileImageUrl,
            'image_urls' => $this->extractImageUrls($html, $profileImageUrl),
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

    private function extractCounts(array $texts): array
    {
        $visibleCounts = $this->extractCountsFromSource($texts['body_text_preview'] ?? null);
        $metaCounts = $this->extractCountsFromSource($texts['description_meta'] ?? null);
        $htmlCounts = $this->extractCountsFromSource($texts['html_document'] ?? null);
        $sources = collect($visibleCounts)
            ->map(fn ($value) => $value !== null ? 'body_text_preview' : null)
            ->all();

        return [
            'posts' => $visibleCounts['posts'],
            'followers' => $visibleCounts['followers'],
            'following' => $visibleCounts['following'],
            'sources' => $sources,
            'warnings' => $this->buildCountWarnings($visibleCounts, $metaCounts, $htmlCounts),
            'visible_complete' => $visibleCounts['posts'] !== null
                && $visibleCounts['followers'] !== null
                && $visibleCounts['following'] !== null,
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

    private function normalizeSourceText(?string $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function buildCountWarnings(array $visibleCounts, array $metaCounts, array $htmlCounts): array
    {
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
        $hasFallbackCounts = collect($metaCounts)->contains(fn ($value) => $value !== null)
            || collect($htmlCounts)->contains(fn ($value) => $value !== null);

        if (! $hasVisibleCounts && $hasFallbackCounts) {
            $warnings[] = 'Instagram hat keine sichtbaren Kennzahlen geliefert; Meta- und HTML-Werte werden bewusst nicht als offizielle Zahlen gespeichert.';
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
