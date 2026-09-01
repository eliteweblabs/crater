<?php

namespace Crater\Http\Controllers;

use Crater\Models\Invoice;
use Crater\Support\InvoiceOgIcons;
use Crater\Support\ReaveBrandColors;
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
            && $invoice->hasCustomerSelectableItems();

        $ogImageUrl = url('/invoices/'.$invoice->unique_hash.'/og.png').'?v=reave';
        $brand = ReaveBrandColors::fetch();

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
            'ogImageUrl',
            'brand'
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

        $size = 360;
        $x = (int) (($w - $size) / 2);
        $y = (int) (($h - $size) / 2);
        $bytes = $this->companyIconBytes();
        $src = $bytes ? @imagecreatefromstring($bytes) : false;
        if ($src) {
            $this->pasteContain($im, $src, $x, $y, $size, $size);
            imagedestroy($src);
        }

        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
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

    private function companyIconBytes(): ?string
    {
        $origin = InvoiceOgIcons::reaveOrigin(config('crater.reave_app_url'));
        $urls = array_filter([
            InvoiceOgIcons::safeHttpUrl(config('crater.company_icon_url')),
            InvoiceOgIcons::companyBrandIconUrl($origin),
        ]);

        foreach ($urls as $url) {
            $bytes = $this->fetchImageBytes($url);
            if ($bytes) {
                return $bytes;
            }
        }

        return null;
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
}
