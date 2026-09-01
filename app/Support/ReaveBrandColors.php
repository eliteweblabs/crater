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
        $logoUrl = self::resolveLogoUrl($origin, $logoEmailUrl);

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
            'source' => $payload['source'] ?? 'fallback',
            'stored' => $payload['stored'] ?? ['primary' => null, 'secondary' => null],
            'defaults' => $payload['defaults'] ?? [
                'primary' => self::FALLBACK_PRIMARY,
                'secondary' => self::FALLBACK_SECONDARY,
            ],
            'mail_button' => [
                'primary' => $primary,
                'secondary' => $secondary,
                'accent' => $accent,
                'wired' => true,
            ],
        ];
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
