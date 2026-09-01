<?php

namespace Crater\Services;

use Crater\Support\InvoiceMailPayload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delegate invoice email delivery to REΛVE so the official template + branding apply.
 *
 * REΛVE endpoint (to implement on reave.app):
 *   POST {REAVE_APP_URL}/api/crater/send-invoice-email
 *   Header: X-API-Key: {CONTACT_API_KEY}
 *   Body: InvoiceMailPayload JSON (see InvoiceMailPayload::fromSendData)
 *
 * Returns true when REΛVE accepts the job (2xx). On failure, Crater falls back to
 * its local Laravel mail template unless INVOICE_MAIL_REAVE_ONLY=true.
 */
class ReaveInvoiceMailDispatcher
{
    public function isEnabled(): bool
    {
        return (bool) config('crater.invoice_mail_via_reave')
            && rtrim((string) config('crater.reave_app_url'), '') !== '';
    }

    /**
     * @param  array  $sendData  Output of Invoice::sendInvoiceData()
     */
    public function dispatch(array $sendData): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $invoiceModel = $sendData['invoice'] ?? null;
        if (! is_array($invoiceModel) || empty($invoiceModel['id'])) {
            return false;
        }

        $invoice = \Crater\Models\Invoice::find($invoiceModel['id']);
        if (! $invoice) {
            return false;
        }

        $payload = InvoiceMailPayload::fromSendData($invoice, $sendData);
        $origin = rtrim((string) config('crater.reave_app_url'), '/');
        $url = $origin.'/api/crater/send-invoice-email';

        try {
            $client = Http::timeout((int) config('contact_api.timeout', 8))
                ->acceptJson()
                ->asJson();

            $key = config('contact_api.key');
            if (! empty($key)) {
                $client = $client->withHeaders(['X-API-Key' => $key]);
            }

            $response = $client->post($url, $payload);

            if ($response->successful()) {
                Log::info('[reave-mail] invoice email delegated', [
                    'invoice_id' => $invoice->id,
                    'to' => $payload['to'],
                ]);

                return true;
            }

            Log::warning('[reave-mail] non-2xx from REΛVE', [
                'invoice_id' => $invoice->id,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[reave-mail] request failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
