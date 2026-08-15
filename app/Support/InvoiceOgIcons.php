<?php

namespace Crater\Support;

/**
 * Pure helpers for invoice OG brand tiles — portal metadata + image bytes.
 * Network I/O stays in PublicInvoiceController so these stay unit-testable.
 */
class InvoiceOgIcons
{
    public static function portalFromContact(?array $contact): array
    {
        foreach ($contact['links'] ?? [] as $link) {
            if (!is_array($link)) {
                continue;
            }
            if (($link['system'] ?? '') === 'portal' && is_array($link['metadata'] ?? null)) {
                return $link['metadata'];
            }
        }

        return [];
    }

    public static function decodeImageData(?string $payload): ?string
    {
        $payload = trim((string) $payload);
        if ($payload === '') {
            return null;
        }

        if (str_starts_with($payload, 'data:') && str_contains($payload, ',')) {
            $payload = substr($payload, strpos($payload, ',') + 1);
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || $raw === '' || self::isSvg($raw)) {
            return null;
        }

        return $raw;
    }

    public static function isSvg(string $bytes): bool
    {
        $head = ltrim(substr($bytes, 0, 256));

        return strncasecmp($head, '<svg', 4) === 0
            || strncasecmp($head, '<?xml', 5) === 0;
    }

    public static function safeHttpUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }

    /**
     * Client icon candidates from portal metadata, in preference order.
     * Each item is either ['data' => base64] or ['url' => https].
     *
     * @return list<array{data?: string, url?: string}>
     */
    public static function clientIconSources(array $portal, string $origin = 'https://reave.app'): array
    {
        $sources = [];

        if (!empty($portal['iconData'])) {
            $sources[] = ['data' => (string) $portal['iconData']];
        }

        $iconUrl = self::safeHttpUrl($portal['iconUrl'] ?? null)
            ?? self::absolutizeReaveUrl($portal['iconUrl'] ?? null, $origin);
        if ($iconUrl) {
            $sources[] = ['url' => $iconUrl];
        }

        if (!empty($portal['logoData'])) {
            $sources[] = ['data' => (string) $portal['logoData']];
        }

        $logoUrl = self::safeHttpUrl($portal['logoUrl'] ?? null)
            ?? self::absolutizeReaveUrl($portal['logoUrl'] ?? null, $origin);
        if ($logoUrl) {
            $sources[] = ['url' => $logoUrl];
        }

        return $sources;
    }

    public static function reaveOrigin(?string $configured = null): string
    {
        $configured = trim((string) $configured);
        if (preg_match('#^https?://#i', $configured)) {
            return rtrim($configured, '/');
        }

        return 'https://reave.app';
    }

    /**
     * @return list<string>
     */
    public static function clientServeUrls(string $uid, string $origin): array
    {
        $uid = trim($uid);
        if ($uid === '') {
            return [];
        }

        $base = rtrim($origin, '/');

        return [
            $base.'/api/clients/'.rawurlencode($uid).'/icon',
            $base.'/api/clients/'.rawurlencode($uid).'/logo',
        ];
    }

    public static function companyBrandIconUrl(string $origin): string
    {
        return rtrim($origin, '/').'/api/branding/icon?size=256&transparent=1';
    }

    public static function absolutizeReaveUrl(?string $url, string $origin): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return rtrim($origin, '/').$url;
        }

        return null;
    }

    /**
     * When the company logo lives on reave.app, also try the raster branding icon.
     */
    public static function reaveBrandingIconUrl(?string $url): ?string
    {
        $url = self::safeHttpUrl($url);
        if (!$url) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (!in_array($host, ['reave.app', 'www.reave.app'], true)) {
            return null;
        }
        if (str_contains($path, '/api/branding/icon')) {
            return null;
        }

        $origin = ($parts['scheme'] ?? 'https').'://'.$host;

        return $origin.'/api/branding/icon?size=256&transparent=1';
    }
}
