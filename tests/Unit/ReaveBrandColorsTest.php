<?php

use Crater\Support\ReaveBrandColors;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget('reave_brand_colors');
});

test('logoUrl and logoEmailUrl read from cached branding payload', function () {
    Cache::put('reave_brand_colors', [
        'logoUrl' => 'https://reave.app/api/branding/logo?v=abc',
        'logoEmailUrl' => 'https://reave.app/api/branding/logo?email=1&v=abc',
        'ogUrl' => 'https://reave.app/api/branding/og.png?v=abc',
        'primary' => '#000000',
        'secondary' => '#505050',
    ], 60);

    expect(ReaveBrandColors::logoUrl())->toBe('https://reave.app/api/branding/logo?v=abc');
    expect(ReaveBrandColors::logoEmailUrl())->toBe('https://reave.app/api/branding/logo?email=1&v=abc');
    expect(ReaveBrandColors::ogUrl())->toBe('https://reave.app/api/branding/og.png?v=abc');
});

test('fetchFresh loads logo, og card, and colors from reave branding api', function () {
    config(['crater.reave_app_url' => 'https://reave.app']);

    $brand = ReaveBrandColors::fetchFresh();

    expect($brand['logoUrl'])->toContain('https://reave.app/api/branding/logo');
    expect($brand['ogUrl'])->toContain('https://reave.app/api/branding/og.png');
    expect($brand['primary'])->toMatch('/^#[0-9a-f]{6}$/i');
    expect($brand['gradient'])->toContain('linear-gradient');
    expect($brand)->toHaveKeys(['contactName', 'contactEmail']);
});

test('resolvedCompanyLogoUrl ignores legacy static branding env paths', function () {
    Cache::put('reave_brand_colors', [
        'logoUrl' => 'https://reave.app/api/branding/logo?v=abc',
        'logoEmailUrl' => null,
        'primary' => '#000000',
        'secondary' => '#505050',
    ], 60);

    config(['crater.company_logo_url' => 'https://reave.app/branding/logo.alt.png']);

    expect(ReaveBrandColors::resolvedCompanyLogoUrl())
        ->toBe('https://reave.app/api/branding/logo?v=abc');
});
