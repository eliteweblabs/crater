<?php

namespace Crater\Http\Controllers;

use Crater\Models\Invoice;
use Crater\Services\ContactApiClient;
use Crater\Support\InvoiceOgIcons;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class PublicInvoiceController extends Controller
{
    public const OG_WIDTH = 1200;

    public const OG_HEIGHT = 630;

    public function show($uniqueHash)
    {
        $invoice = Invoice::with(['customer', 'company', 'items'])
            ->where('unique_hash', $uniqueHash)
            ->firstOrFail();

        $hasOptionalItems = $invoice->paid_status !== Invoice::STATUS_PAID
            && $invoice->items->contains(fn ($item) => $item->isOptional());

        $ogImageUrl = url('/invoices/'.$invoice->unique_hash.'/og.png').'?v=pair';

        // Get all non-draft invoices for the same customer
        $customerInvoices = Invoice::where('customer_id', $invoice->customer_id)
            ->where('company_id', $invoice->company_id)
            ->where('status', '<>', 'DRAFT')
            ->where('id', '<>', $invoice->id)  // Exclude current invoice
            ->orderBy('created_at', 'desc')
            ->get(['id', 'invoice_number', 'total', 'status', 'paid_status', 'due_date', 'unique_hash']);

        return view('app.public.invoice-view', compact(
            'invoice',
            'customerInvoices',
            'hasOptionalItems',
            'ogImageUrl'
        ));
    }

    /**
     * 1200×630 card for iMessage / Slack / social previews of the public invoice URL.
     */
    public function ogImage($uniqueHash): Response
    {
        $invoice = Invoice::with(['customer', 'company'])
            ->where('unique_hash', $uniqueHash)
            ->firstOrFail();

        try {
            $png = $this->renderOgPng($invoice);
        } catch (\Throwable $e) {
            \Log::error('invoice og image failed', [
                'hash' => $uniqueHash,
                'error' => $e->getMessage(),
            ]);
            $png = $this->fallbackOgPng($invoice);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function renderOgPng(Invoice $invoice): string
    {
        $w = self::OG_WIDTH;
        $h = self::OG_HEIGHT;
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);
        imagesavealpha($im, true);

        $bg = imagecolorallocate($im, 10, 10, 10);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        $company = $invoice->company->name ?? 'Invoice';
        $customer = $invoice->customer->name ?? '';

        $iconSize = 280;
        $iconGap = 56;
        $pairWidth = ($iconSize * 2) + $iconGap;
        $iconX = (int) (($w - $pairWidth) / 2);
        $iconY = (int) (($h - $iconSize) / 2);

        $this->paintIconTile(
            $im,
            $this->companyIconBytes($invoice),
            $company,
            $iconX,
            $iconY,
            $iconSize
        );
        $this->paintIconTile(
            $im,
            $this->clientIconBytes($invoice),
            $customer !== '' ? $customer : $company,
            $iconX + $iconSize + $iconGap,
            $iconY,
            $iconSize
        );

        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    private function canPaintTtf(): bool
    {
        return function_exists('imagettfbbox') && function_exists('imagettftext');
    }

    private function paintIconTile($im, ?string $bytes, string $label, int $x, int $y, int $size): void
    {
        $radius = (int) ($size * 0.22);
        $tile = imagecolorallocatealpha($im, 36, 20, 52, 36);
        $this->fillRoundRect($im, $x, $y, $size, $size, $radius, $tile);

        $src = $bytes ? @imagecreatefromstring($bytes) : false;
        if ($src) {
            $pad = 16;
            $this->pasteContain($im, $src, $x + $pad, $y + $pad, $size - ($pad * 2), $size - ($pad * 2));
            imagedestroy($src);

            return;
        }

        $letter = mb_strtoupper(mb_substr(trim($label), 0, 1));
        if ($letter === '') {
            return;
        }

        $white = imagecolorallocate($im, 255, 255, 255);
        $font = $this->fontPath(true) ?? $this->fontPath(false);
        if (!$font) {
            imagestring($im, 5, $x + (int) ($size / 2) - 6, $y + (int) ($size / 2) - 8, $letter, $white);

            return;
        }

        $fontSize = 72;
        $box = \imagettfbbox($fontSize, 0, $font, $letter);
        $tw = abs($box[2] - $box[0]);
        $th = abs($box[7] - $box[1]);
        \imagettftext(
            $im,
            $fontSize,
            0,
            $x + (int) (($size - $tw) / 2),
            $y + (int) (($size + $th) / 2),
            $white,
            $font,
            $letter
        );
    }

    private function fillRoundRect($im, int $x, int $y, int $w, int $h, int $r, $color): void
    {
        $r = max(1, min($r, (int) ($w / 2), (int) ($h / 2)));
        imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $color);
        imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $color);
        imagefilledellipse($im, $x + $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x + $w - $r, $y + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x + $r, $y + $h - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, $color);
    }

    private function pasteContain($dst, $src, int $x, int $y, int $boxW, int $boxH): void
    {
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW < 1 || $srcH < 1) {
            return;
        }

        $scale = min($boxW / $srcW, $boxH / $srcH);
        $dw = max(1, (int) round($srcW * $scale));
        $dh = max(1, (int) round($srcH * $scale));
        $dx = $x + (int) (($boxW - $dw) / 2);
        $dy = $y + (int) (($boxH - $dh) / 2);
        imagecopyresampled($dst, $src, $dx, $dy, 0, 0, $dw, $dh, $srcW, $srcH);
    }

    private function companyIconBytes(Invoice $invoice): ?string
    {
        $company = $invoice->company;
        if (!$company) {
            return null;
        }

        $origin = InvoiceOgIcons::reaveOrigin(config('crater.reave_app_url'));
        $logoUrl = trim((string) ($company->logo ?? config('crater.company_logo_url') ?? ''));
        $urls = array_filter([
            InvoiceOgIcons::safeHttpUrl(config('crater.company_icon_url')),
            InvoiceOgIcons::companyBrandIconUrl($origin),
            InvoiceOgIcons::safeHttpUrl($logoUrl),
            InvoiceOgIcons::reaveBrandingIconUrl($logoUrl),
        ]);

        foreach (array_unique($urls) as $url) {
            $bytes = $this->fetchImageBytes($url);
            if ($bytes) {
                return $bytes;
            }
        }

        $media = $company->getMedia('logo')->first();
        if ($media && is_readable($media->getPath())) {
            $bytes = @file_get_contents($media->getPath());
            if (is_string($bytes) && $bytes !== '' && !InvoiceOgIcons::isSvg($bytes)) {
                return $bytes;
            }
        }

        return null;
    }

    private function clientIconBytes(Invoice $invoice): ?string
    {
        $origin = InvoiceOgIcons::reaveOrigin(config('crater.reave_app_url'));
        $uid = $this->contactUid($invoice);

        // Uploaded icons live on Reave, not as a full URL in contact-api.
        foreach (InvoiceOgIcons::clientServeUrls((string) $uid, $origin) as $url) {
            $bytes = $this->fetchImageBytes($url);
            if ($bytes) {
                return $bytes;
            }
        }

        $contact = $this->loadContact($invoice, $uid);
        $sources = InvoiceOgIcons::clientIconSources(
            InvoiceOgIcons::portalFromContact($contact),
            $origin
        );

        foreach ($sources as $source) {
            if (!empty($source['data'])) {
                $bytes = InvoiceOgIcons::decodeImageData($source['data']);
                if ($bytes) {
                    return $bytes;
                }
            }
            if (!empty($source['url'])) {
                $bytes = $this->fetchImageBytes($source['url']);
                if ($bytes) {
                    return $bytes;
                }
            }
        }

        return null;
    }

    private function contactUid(Invoice $invoice): ?string
    {
        $uid = trim((string) ($invoice->customer->contact_uid ?? ''));
        if ($uid !== '') {
            return $uid;
        }

        $api = app(ContactApiClient::class);
        if (!$api->isEnabled()) {
            return null;
        }

        $customer = $invoice->customer;
        $resolved = $api->resolve(
            $customer->name ?? null,
            $customer->email ?? null,
            $customer->phone ?? null
        );
        if (!is_array($resolved) || !in_array($resolved['match'] ?? '', ['exact', 'likely'], true)) {
            return null;
        }

        $found = trim((string) data_get($resolved, 'contact.uid', ''));

        return $found !== '' ? $found : null;
    }

    private function loadContact(Invoice $invoice, ?string $uid = null): ?array
    {
        $api = app(ContactApiClient::class);
        if (!$api->isEnabled()) {
            return null;
        }

        $uid = trim((string) ($uid ?: $invoice->customer->contact_uid ?? ''));
        if ($uid === '') {
            return null;
        }

        return $api->get($uid, 8);
    }

    private function fetchImageBytes(string $url): ?string
    {
        $url = InvoiceOgIcons::safeHttpUrl($url);
        if (!$url) {
            return null;
        }

        try {
            $res = Http::timeout(5)
                ->withHeaders([
                    'Accept' => 'image/*,*/*;q=0.8',
                    'User-Agent' => 'CraterInvoice/1.0',
                ])
                ->get($url);
            if (!$res->successful()) {
                return null;
            }
            $bytes = $res->body();
            if ($bytes === '' || InvoiceOgIcons::isSvg($bytes)) {
                return null;
            }
            $probe = @imagecreatefromstring($bytes);
            if (!$probe) {
                return null;
            }
            imagedestroy($probe);

            return $bytes;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fallbackOgPng(Invoice $invoice): string
    {
        $im = imagecreatetruecolor(self::OG_WIDTH, self::OG_HEIGHT);
        imagefilledrectangle($im, 0, 0, self::OG_WIDTH, self::OG_HEIGHT, imagecolorallocate($im, 12, 6, 20));
        $white = imagecolorallocate($im, 255, 255, 255);
        imagestring($im, 5, 72, 200, (string) ($invoice->invoice_number ?: 'Invoice'), $white);
        imagestring($im, 5, 72, 240, '$'.number_format(((int) $invoice->total) / 100, 2), $white);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    private function fontPath(bool $bold): ?string
    {
        if (! $this->canPaintTtf()) {
            return null;
        }

        $name = $bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf';
        $candidates = [
            resource_path('fonts/'.$name),
            '/usr/share/fonts/truetype/dejavu/'.$name,
        ];

        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }
}
