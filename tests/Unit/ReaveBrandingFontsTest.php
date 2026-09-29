<?php

use Crater\Support\ReaveBrandColors;

test('normalizeFonts uses API payload when present', function () {
    $fonts = ReaveBrandColors::normalizeFonts([
        'fontPrimary' => '"Comfortaa", sans-serif',
        'fontContent' => '"Outfit", sans-serif',
        'googleFontsHref' => 'https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;700&display=swap',
        'fontPrimaryId' => 'google:comfortaa',
    ]);

    expect($fonts['primary'])->toBe('"Comfortaa", sans-serif');
    expect($fonts['content'])->toBe('"Outfit", sans-serif');
    expect($fonts['googleFontsHref'])->toContain('Comfortaa');
    expect($fonts['primaryId'])->toBe('google:comfortaa');
});

test('normalizeFonts falls back when API omits fonts', function () {
    $fonts = ReaveBrandColors::normalizeFonts(null);

    expect($fonts['primary'])->toBe(ReaveBrandColors::FALLBACK_FONT_PRIMARY);
    expect($fonts['content'])->toBe(ReaveBrandColors::FALLBACK_FONT_CONTENT);
});

test('stripeFontFamily extracts first face', function () {
    expect(ReaveBrandColors::stripeFontFamily('"Comfortaa", sans-serif'))
        ->toBe('Comfortaa, system-ui, sans-serif');
});
