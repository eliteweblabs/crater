<?php

use Crater\Support\InvoiceOgIcons;

test('extracts portal metadata from contact links', function () {
    $portal = InvoiceOgIcons::portalFromContact([
        'links' => [
            ['system' => 'crater', 'metadata' => ['ignored' => true]],
            ['system' => 'portal', 'metadata' => ['iconUrl' => 'https://cdn.example/icon.png']],
        ],
    ]);

    expect($portal['iconUrl'])->toBe('https://cdn.example/icon.png');
});

test('ignores contacts without a portal link', function () {
    expect(InvoiceOgIcons::portalFromContact(['links' => [['system' => 'stripe']]]))->toBe([]);
    expect(InvoiceOgIcons::portalFromContact(null))->toBe([]);
});

test('prefers uploaded icon data then remote icon then logo', function () {
    $sources = InvoiceOgIcons::clientIconSources([
        'iconData' => 'abc',
        'iconUrl' => 'https://cdn.example/icon.png',
        'logoData' => 'def',
        'logoUrl' => 'https://cdn.example/logo.png',
    ]);

    expect($sources)->toHaveCount(4)
        ->and($sources[0])->toBe(['data' => 'abc'])
        ->and($sources[1])->toBe(['url' => 'https://cdn.example/icon.png'])
        ->and($sources[2])->toBe(['data' => 'def'])
        ->and($sources[3])->toBe(['url' => 'https://cdn.example/logo.png']);
});

test('resolves relative reave icon paths and skips unsafe urls', function () {
    $sources = InvoiceOgIcons::clientIconSources([
        'iconUrl' => '/api/clients/abc/icon',
        'logoUrl' => 'javascript:alert(1)',
    ], 'https://rekko.studio');

    expect($sources)->toBe([['url' => 'https://rekko.studio/api/clients/abc/icon']]);
});

test('builds reave client serve urls from a contact uid', function () {
    expect(InvoiceOgIcons::clientServeUrls('abc-123', 'https://rekko.studio'))->toBe([
        'https://rekko.studio/api/clients/abc-123/icon',
        'https://rekko.studio/api/clients/abc-123/logo',
    ]);
    expect(InvoiceOgIcons::companyBrandIconUrl('https://rekko.studio'))
        ->toBe('https://rekko.studio/api/branding/icon?size=256&transparent=1');
});

test('decodes raw and data-uri image payloads and rejects svg', function () {
    $png = base64_encode('not-really-png-but-ok');
    expect(InvoiceOgIcons::decodeImageData($png))->toBe('not-really-png-but-ok');
    expect(InvoiceOgIcons::decodeImageData('data:image/png;base64,'.$png))->toBe('not-really-png-but-ok');
    expect(InvoiceOgIcons::decodeImageData(base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>')))->toBeNull();
    expect(InvoiceOgIcons::decodeImageData(''))->toBeNull();
});

test('derives a rekko.studio branding icon from a logo url on that host', function () {
    expect(InvoiceOgIcons::reaveBrandingIconUrl('https://rekko.studio/logo.svg'))
        ->toBe('https://rekko.studio/api/branding/icon?size=256&transparent=1');
    expect(InvoiceOgIcons::reaveBrandingIconUrl('https://ap.rekko.studio/logo.png'))
        ->toBe('https://ap.rekko.studio/api/branding/icon?size=256&transparent=1');
    expect(InvoiceOgIcons::reaveBrandingIconUrl('https://ap.reave.app/logo.png'))->toBeNull();
    expect(InvoiceOgIcons::reaveBrandingIconUrl('https://cdn.example/logo.png'))->toBeNull();
    expect(InvoiceOgIcons::reaveBrandingIconUrl('https://rekko.studio/api/branding/icon?size=64'))->toBeNull();
});
