<?php

namespace Crater\Support;

/**
 * Public marketing / branding site (logo, colors, OG) — not the invoicing app host.
 */
class BrandSite
{
    public const DEFAULT_ORIGIN = 'https://rekko.studio';

    public static function origin(): string
    {
        $configured = rtrim((string) config('crater.reave_app_url'), '/');

        return $configured !== '' ? $configured : self::DEFAULT_ORIGIN;
    }

    /** Hosts that serve /api/branding* assets for emails and public invoices. */
    public static function isBrandingHost(string $host): bool
    {
        $host = strtolower($host);

        if (self::isRekkoHost($host)) {
            return true;
        }

        return in_array($host, ['reave.app', 'www.reave.app', 'ap.reave.app'], true);
    }

    public static function isRekkoHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'rekko.studio' || str_ends_with($host, '.rekko.studio');
    }
}
