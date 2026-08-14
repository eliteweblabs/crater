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

        // Optional add-ons always start off on the public page. Stored qty is
        // not a customer choice — Crater defaults new lines to 1, which was
        // turning the last optional (e.g. Google Workspace) on.
        $publicSubtotal = (int) $invoice->items->sum(
            fn ($item) => $item->isOptional() ? 0 : (int) $item->total
        );
        $publicTotal = max(0, $publicSubtotal - (int) ($invoice->discount_val ?? 0) + (int) $invoice->tax);

        $ogImageUrl = url('/invoices/'.$invoice->unique_hash.'/og.png').'?v=icons';

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
            'publicSubtotal',
            'publicTotal',
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

        $bg = imagecolorallocate($im, 12, 6, 20);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        $this->paintGlow($im, (int) ($w * 0.18), (int) ($h * 0.15), 520, 192, 38, 211, 0.28);
        $this->paintGlow($im, (int) ($w * 0.82), (int) ($h * 0.75), 480, 99, 102, 241, 0.22);

        $bar = imagecreatetruecolor($w, 10);
        for ($x = 0; $x < $w; $x++) {
            $t = $x / max(1, $w - 1);
            if ($t < 0.52) {
                $u = $t / 0.52;
                $r = (int) (244 + (192 - 244) * $u);
                $g = (int) (114 + (38 - 114) * $u);
                $b = (int) (182 + (211 - 182) * $u);
            } else {
                $u = ($t - 0.52) / 0.48;
                $r = (int) (192 + (99 - 192) * $u);
                $g = (int) (38 + (102 - 38) * $u);
                $b = (int) (211 + (241 - 211) * $u);
            }
            imageline($bar, $x, 0, $x, 9, imagecolorallocate($bar, $r, $g, $b));
        }
        imagecopy($im, $bar, 0, 0, 0, 0, $w, 10);
        imagedestroy($bar);

        $white = imagecolorallocate($im, 255, 255, 255);
        $muted = imagecolorallocate($im, 196, 184, 210);
        $pink = imagecolorallocate($im, 244, 114, 182);

        $regular = $this->fontPath(false);
        $bold = $this->fontPath(true) ?? $regular;

        $company = $invoice->company->name ?? 'Invoice';
        $number = $invoice->invoice_number ?: 'Invoice';
        $customer = $invoice->customer->name ?? '';
        $amount = '$'.number_format(((int) $invoice->total) / 100, 2);
        $due = $invoice->formattedDueDate ? 'Due '.$invoice->formattedDueDate : '';

        $iconSize = 188;
        $iconGap = 40;
        $pairWidth = ($iconSize * 2) + $iconGap;
        $iconX = (int) (($w - $pairWidth) / 2);
        $iconY = 72;

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

        $textTop = $iconY + $iconSize + 56;

        if ($regular) {
            $this->drawCentered($im, 20, $textTop, $pink, $regular, 'INVOICE');
            $this->drawCentered($im, 42, $textTop + 58, $white, $bold ?? $regular, $this->fitText($number, $bold ?? $regular, 42, 1000));
            $this->drawCentered($im, 64, $textTop + 140, $white, $bold ?? $regular, $this->fitText($amount, $bold ?? $regular, 64, 1000));
            if ($customer !== '') {
                $this->drawCentered($im, 24, $textTop + 200, $muted, $regular, $this->fitText($customer, $regular, 24, 1000));
            }
            if ($due !== '') {
                $this->drawCentered($im, 20, $textTop + 242, $muted, $regular, $due);
            }
        } else {
            imagestring($im, 5, 72, 360, 'INVOICE', $pink);
            imagestring($im, 5, 72, 400, $number, $white);
            imagestring($im, 5, 72, 440, $amount, $white);
            imagestring($im, 4, 72, 480, $customer, $muted);
        }

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

    private function drawCentered($im, int $size, int $baselineY, $color, string $font, string $text): void
    {
        $box = \imagettfbbox($size, 0, $font, $text);
        $width = abs($box[2] - $box[0]);
        $x = (int) ((self::OG_WIDTH - $width) / 2);
        \imagettftext($im, $size, 0, $x, $baselineY, $color, $font, $text);
    }

    private function paintIconTile($im, ?string $bytes, string $label, int $x, int $y, int $size): void
    {
        $radius = (int) ($size * 0.22);
        $tile = imagecolorallocatealpha($im, 36, 20, 52, 36);
        $this->fillRoundRect($im, $x, $y, $size, $size, $radius, $tile);

        $src = $bytes ? @imagecreatefromstring($bytes) : false;
        if ($src) {
            $pad = 20;
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

        $logoUrl = trim((string) ($company->logo ?? config('crater.company_logo_url') ?? ''));
        $urls = array_filter([
            InvoiceOgIcons::safeHttpUrl(config('crater.company_icon_url')),
            InvoiceOgIcons::safeHttpUrl($logoUrl),
            InvoiceOgIcons::reaveBrandingIconUrl(config('crater.company_icon_url')),
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
        $contact = $this->loadContact($invoice);
        $sources = InvoiceOgIcons::clientIconSources(InvoiceOgIcons::portalFromContact($contact));

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

    private function loadContact(Invoice $invoice): ?array
    {
        $api = app(ContactApiClient::class);
        if (!$api->isEnabled()) {
            return null;
        }

        $customer = $invoice->customer;
        $uid = trim((string) ($customer->contact_uid ?? ''));
        if ($uid !== '') {
            $contact = $api->get($uid, 8);
            if ($contact) {
                return $contact;
            }
        }

        $resolved = $api->resolve(
            $customer->name ?? null,
            $customer->email ?? null,
            $customer->phone ?? null
        );
        if (!is_array($resolved) || !in_array($resolved['match'] ?? '', ['exact', 'likely'], true)) {
            return null;
        }

        return is_array($resolved['contact'] ?? null) ? $resolved['contact'] : null;
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

    private function paintGlow($im, int $cx, int $cy, int $radius, int $r, int $g, int $b, float $alpha): void
    {
        imagesetthickness($im, 1);
        for ($i = $radius; $i > 0; $i -= 8) {
            $t = 1 - ($i / $radius);
            $a = (int) min(127, round((1 - ($alpha * $t * $t)) * 127));
            $color = imagecolorallocatealpha($im, $r, $g, $b, $a);
            imagefilledellipse($im, $cx, $cy, $i * 2, (int) ($i * 1.4), $color);
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

    private function fitText(string $text, string $font, int $size, int $maxWidth): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        while (mb_strlen($text) > 3) {
            $box = \imagettfbbox($size, 0, $font, $text);
            $width = abs($box[2] - $box[0]);
            if ($width <= $maxWidth) {
                return $text;
            }
            $text = rtrim(mb_substr($text, 0, -2)).'…';
        }

        return $text;
    }
}
