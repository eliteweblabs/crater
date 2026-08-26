<?php

namespace Crater\Support;

/**
 * Company brand colors from reΛVe admin → Company (Postgres).
 * Fetches GET {REAVE_APP_URL}/api/branding/colors.
 */
class ReaveBrandColors
{
    /** Hardcoded invoice CTA stops in vendor/mail/html/button.blade.php */
    public const MAIL_BUTTON_PRIMARY = '#f472b6';
    public const MAIL_BUTTON_SECONDARY = '#c026d3';
    public const MAIL_BUTTON_ACCENT = '#6366f1';

    public static function fetch(): array
    {
        $origin = rtrim((string) config('crater.reave_app_url'), '/');
        $payload = $origin !== '' ? self::getJson($origin.'/api/branding/colors') : null;

        $primary = is_string($payload['primary'] ?? null) ? $payload['primary'] : self::MAIL_BUTTON_PRIMARY;
        $secondary = is_string($payload['secondary'] ?? null) ? $payload['secondary'] : self::MAIL_BUTTON_SECONDARY;

        return [
            'ok' => true,
            'primary' => $primary,
            'secondary' => $secondary,
            'accent' => is_string($payload['accent'] ?? null) ? $payload['accent'] : self::MAIL_BUTTON_ACCENT,
            'primaryRgb' => $payload['primaryRgb'] ?? null,
            'secondaryRgb' => $payload['secondaryRgb'] ?? null,
            'gradient' => $payload['gradient'] ?? ('linear-gradient(135deg, '.$primary.', '.$secondary.')'),
            'source' => $payload['source'] ?? 'fallback',
            'stored' => $payload['stored'] ?? ['primary' => null, 'secondary' => null],
            'defaults' => $payload['defaults'] ?? [
                'primary' => self::MAIL_BUTTON_PRIMARY,
                'secondary' => self::MAIL_BUTTON_SECONDARY,
            ],
            'mail_button' => [
                'primary' => self::MAIL_BUTTON_PRIMARY,
                'secondary' => self::MAIL_BUTTON_SECONDARY,
                'accent' => self::MAIL_BUTTON_ACCENT,
                'wired' => false,
            ],
        ];
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
