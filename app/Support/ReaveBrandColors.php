<?php

namespace Crater\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Company branding from reΛVe admin → Company (Postgres).
 * Fetches GET {REAVE_APP_URL}/api/branding (logo + colors).
 */
class ReaveBrandColors
{
    public const FALLBACK_PRIMARY = '#000000';
    public const FALLBACK_SECONDARY = '#505050';

    public const FALLBACK_FONT_CONTENT = '"Mozilla Text", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    public const FALLBACK_FONT_PRIMARY = '"Space Grotesk", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    public const FALLBACK_FONT_SECONDARY = self::FALLBACK_FONT_PRIMARY;

    public const FALLBACK_GOOGLE_FONTS_HREF = 'https://fonts.googleapis.com/css2?family=Mozilla+Text:ital,wght@0,400;0,500;0,600;1,400&family=Space+Grotesk:wght@400;500;600;700&display=swap';

    public static function fetch(): array
    {
        return Cache::remember('reave_brand_colors', 60, fn () => self::fetchFresh());
    }

    /**
     * Logo for light backgrounds (public invoice page, admin UI).
     */
    public static function logoUrl(): ?string
    {
        $brand = self::fetch();

        return $brand['logoUrl'] ?? null;
    }

    /**
     * Logo rasterized for email / dark backgrounds.
     */
    public static function logoEmailUrl(): ?string
    {
        $brand = self::fetch();

        return $brand['logoEmailUrl'] ?? null;
    }

    /**
     * Default 1200×630 share card from reΛVe admin branding.
     */
    public static function ogUrl(): ?string
    {
        $brand = self::fetch();

        return $brand['ogUrl'] ?? null;
    }

    /**
     * Prefer reΛVe admin branding API; ignore legacy static /branding/* env paths.
     */
    public static function resolvedCompanyLogoUrl(): ?string
    {
        return self::logoUrl() ?: self::envLogoUrlIfValid();
    }

    protected static function envLogoUrlIfValid(): ?string
    {
        $env = trim((string) config('crater.company_logo_url'));
        if ($env === '' || self::isLegacyBrandingPath($env)) {
            return null;
        }

        return $env;
    }

    protected static function isLegacyBrandingPath(string $url): bool
    {
        return str_contains($url, '/branding/logo.alt')
            || str_contains($url, '/branding/logo.');
    }

    public static function fetchFresh(): array
    {
        $origin = rtrim((string) config('crater.reave_app_url'), '/');
        $payload = $origin !== '' ? self::getJson($origin.'/api/branding') : null;
        if ($payload === null && $origin !== '') {
            $payload = self::getJson($origin.'/api/branding/colors');
        }

        $primary = self::hex($payload['primary'] ?? null) ?? self::FALLBACK_PRIMARY;
        $secondary = self::hex($payload['secondary'] ?? null) ?? self::FALLBACK_SECONDARY;
        $accent = self::hex($payload['accent'] ?? null) ?? $secondary;
        $secondaryRgb = is_string($payload['secondaryRgb'] ?? null)
            ? $payload['secondaryRgb']
            : '80, 80, 80';
        $logoEmailUrl = is_string($payload['logoEmailUrl'] ?? null) && $payload['logoEmailUrl'] !== ''
            ? $payload['logoEmailUrl']
            : null;
        $payloadOgUrl = is_string($payload['ogUrl'] ?? null) && $payload['ogUrl'] !== ''
            ? $payload['ogUrl']
            : null;
        $logoUrl = self::resolveLogoUrl($origin, $logoEmailUrl);
        $ogUrl = self::resolveOgUrl($origin, $logoEmailUrl, $payloadOgUrl);
        $contactName = self::nonEmptyString($payload['contactName'] ?? null);
        $contactEmail = self::nonEmptyString($payload['contactEmail'] ?? null);
        $fonts = self::normalizeFonts(is_array($payload['fonts'] ?? null) ? $payload['fonts'] : null);

        return [
            'ok' => true,
            'primary' => $primary,
            'secondary' => $secondary,
            'accent' => $accent,
            'primaryRgb' => $payload['primaryRgb'] ?? null,
            'secondaryRgb' => $secondaryRgb,
            'gradient' => is_string($payload['gradient'] ?? null) && $payload['gradient'] !== ''
                ? $payload['gradient']
                : 'linear-gradient(145deg, '.$primary.' 0%, '.$secondary.' 100%)',
            'shadow' => '0 2px 16px rgba('.$secondaryRgb.', 0.35)',
            'logoEmailUrl' => $logoEmailUrl,
            'logoUrl' => $logoUrl,
            'ogUrl' => $ogUrl,
            'contactName' => $contactName,
            'contactEmail' => $contactEmail,
            'source' => $payload['source'] ?? 'fallback',
            'stored' => $payload['stored'] ?? ['primary' => null, 'secondary' => null],
            'defaults' => $payload['defaults'] ?? [
                'primary' => self::FALLBACK_PRIMARY,
                'secondary' => self::FALLBACK_SECONDARY,
            ],
            'fonts' => $fonts,
            'mail_button' => [
                'primary' => $primary,
                'secondary' => $secondary,
                'accent' => $accent,
                'wired' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{
     *     primary: string,
     *     secondary: string,
     *     content: string,
     *     googleFontsHref: string,
     *     primaryId: string|null,
     *     secondaryId: string|null,
     *     contentId: string|null
     * }
     */
    public static function normalizeFonts(?array $raw): array
    {
        $raw ??= [];

        $primary = self::fontFamilyCss($raw['fontPrimary'] ?? null) ?? self::FALLBACK_FONT_PRIMARY;
        $secondary = self::fontFamilyCss($raw['fontSecondary'] ?? null) ?? self::FALLBACK_FONT_SECONDARY;
        $content = self::fontFamilyCss($raw['fontContent'] ?? null) ?? self::FALLBACK_FONT_CONTENT;
        $href = self::googleFontsHref($raw['googleFontsHref'] ?? null) ?? self::FALLBACK_GOOGLE_FONTS_HREF;

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'content' => $content,
            'googleFontsHref' => $href,
            'primaryId' => is_string($raw['fontPrimaryId'] ?? null) ? $raw['fontPrimaryId'] : null,
            'secondaryId' => is_string($raw['fontSecondaryId'] ?? null) ? $raw['fontSecondaryId'] : null,
            'contentId' => is_string($raw['fontContentId'] ?? null) ? $raw['fontContentId'] : null,
        ];
    }

    public static function fontFamilyCss(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $t = trim($raw);
        if ($t === '' || strlen($t) > 512) {
            return null;
        }

        return $t;
    }

    public static function googleFontsHref(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $t = trim($raw);
        if ($t === '' || ! str_starts_with($t, 'https://fonts.googleapis.com/')) {
            return null;
        }

        return $t;
    }

    public static function stripeFontFamily(string $fontFamilyCss): string
    {
        if (preg_match('/"([^"]+)"/', $fontFamilyCss, $m)) {
            return $m[1].', system-ui, sans-serif';
        }

        return 'system-ui, sans-serif';
    }

    protected static function resolveLogoUrl(string $origin, ?string $logoEmailUrl): ?string
    {
        if ($origin === '') {
            return null;
        }

        $cacheBust = '';
        if ($logoEmailUrl && preg_match('/[?&]v=([^&]+)/', $logoEmailUrl, $matches)) {
            $cacheBust = '?v='.urlencode(urldecode($matches[1]));
        }

        return $origin.'/api/branding/logo'.$cacheBust;
    }

    protected static function resolveOgUrl(string $origin, ?string $logoEmailUrl, ?string $payloadOgUrl): ?string
    {
        if ($origin === '') {
            return null;
        }

        if ($payloadOgUrl) {
            return $payloadOgUrl;
        }

        $cacheBust = '';
        if ($logoEmailUrl && preg_match('/[?&]v=([^&]+)/', $logoEmailUrl, $matches)) {
            $cacheBust = '?v='.urlencode(urldecode($matches[1]));
        }

        return $origin.'/api/branding/og.png'.$cacheBust;
    }

    public static function hex(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $t = trim($raw);
        if (! preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $t)) {
            return null;
        }
        if (strlen($t) === 4) {
            return '#'.$t[1].$t[1].$t[2].$t[2].$t[3].$t[3];
        }

        return strtolower($t);
    }

    protected static function nonEmptyString(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $t = trim($raw);

        return $t !== '' ? $t : null;
    }

    protected static function getJson(string $url): ?array
    {
        if (! function_exists('curl_init')) {
            $raw = @file_get_contents($url);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($decoded) ? $decoded : null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code !== 200) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
