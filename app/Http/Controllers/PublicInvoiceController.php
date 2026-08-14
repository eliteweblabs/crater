<?php

namespace Crater\Http\Controllers;

use Crater\Models\Invoice;
use Illuminate\Http\Response;

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

        $ogImageUrl = url('/invoices/'.$invoice->unique_hash.'/og.png');

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

        $png = $this->renderOgPng($invoice);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
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

        if ($regular) {
            imagettftext($im, 22, 0, 72, 120, $pink, $regular, 'INVOICE');
            imagettftext($im, 56, 0, 72, 210, $white, $bold ?? $regular, $this->fitText($number, $bold ?? $regular, 56, 720));
            imagettftext($im, 72, 0, 72, 340, $white, $bold ?? $regular, $this->fitText($amount, $bold ?? $regular, 72, 900));
            if ($customer !== '') {
                imagettftext($im, 28, 0, 72, 430, $muted, $regular, $this->fitText($customer, $regular, 28, 1000));
            }
            if ($due !== '') {
                imagettftext($im, 22, 0, 72, 480, $muted, $regular, $due);
            }
            imagettftext($im, 22, 0, 72, 580, $white, $regular, $this->fitText($company, $regular, 22, 1000));
        } else {
            imagestring($im, 5, 72, 90, 'INVOICE', $pink);
            imagestring($im, 5, 72, 160, $number, $white);
            imagestring($im, 5, 72, 230, $amount, $white);
            imagestring($im, 4, 72, 300, $customer, $muted);
            imagestring($im, 3, 72, 540, $company, $white);
        }

        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
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

    private function fontPath(bool $bold): ?string
    {
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
            $box = imagettfbbox($size, 0, $font, $text);
            $width = abs($box[2] - $box[0]);
            if ($width <= $maxWidth) {
                return $text;
            }
            $text = rtrim(mb_substr($text, 0, -2)).'…';
        }

        return $text;
    }
}
