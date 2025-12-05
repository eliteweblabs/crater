<?php

namespace Crater\Http\Controllers\V1\Customer\Payment;

use Crater\Http\Controllers\Controller;
use Crater\Models\Invoice;
use Crater\Models\Payment;
use Crater\Models\PaymentMethod;
use Crater\Models\Transaction;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;

class StripePaymentController extends Controller
{
    /**
     * Create a Stripe checkout session for an invoice
     * Accepts either Invoice model (route model binding) or invoice ID
     */
    public function createCheckoutSession(Request $request, $invoice)
    {
        try {
            // If invoice is an ID, fetch the model
            if (!$invoice instanceof Invoice) {
                $invoice = Invoice::with(['customer', 'company'])->findOrFail($invoice);
            } else {
                // Load relationships if not already loaded
                $invoice->load(['customer', 'company']);
            }
            
            // Check if invoice is already paid
            if ($invoice->paid_status === 'PAID') {
                return response()->json(['error' => 'Invoice is already paid'], 400);
            }

            // Initialize Stripe
            Stripe::setApiKey(config('services.stripe.secret'));

            // Convert amount to cents for Stripe (Stripe requires amounts in smallest currency unit)
            // Zero-decimal currencies (JPY, KRW, etc.) don't need conversion
            $currencyCode = strtolower($invoice->currency->code);
            $zeroDecimalCurrencies = ['jpy', 'krw', 'clp', 'vnd', 'xof', 'xaf', 'bif', 'djf', 'gnf', 'kmf', 'mga', 'rwf', 'xpf', 'vuv', 'ugx'];
            $amountInCents = in_array($currencyCode, $zeroDecimalCurrencies) 
                ? (int)$invoice->due_amount 
                : (int)($invoice->due_amount * 100);

            // Create Stripe checkout session
            // Payment methods: card (includes Apple Pay/Google Pay), link (Stripe 1-click), cashapp, us_bank_account (ACH)
            $session = StripeSession::create([
                'payment_method_types' => ['card', 'link', 'cashapp', 'us_bank_account'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currencyCode,
                        'product_data' => [
                            'name' => 'Invoice #' . $invoice->invoice_number,
                            'description' => 'Payment for ' . $invoice->company->name,
                        ],
                        'unit_amount' => $amountInCents,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => url("/{$invoice->company->slug}/customer/invoices/{$invoice->id}?payment=success"),
                'cancel_url' => url("/{$invoice->company->slug}/customer/invoices/{$invoice->id}?payment=cancelled"),
                'client_reference_id' => $invoice->id,
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'company_id' => $invoice->company_id,
                    'customer_id' => $invoice->customer_id,
                ],
            ]);

            // Create a pending transaction
            Transaction::createTransaction([
                'transaction_id' => $session->id,
                'type' => 'stripe',
                'status' => Transaction::PENDING,
                'transaction_date' => now(),
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
            ]);

            return response()->json([
                'sessionId' => $session->id,
                'url' => $session->url,
            ]);

        } catch (\Exception $e) {
            \Log::error('Stripe checkout error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a Stripe checkout session for public invoice links (no auth required)
     * Redirects directly to Stripe instead of returning JSON
     */
    public function createCheckoutSessionPublic(Request $request, $invoice)
    {
        try {
            // If invoice is an ID or unique_hash, fetch the model
            if (!$invoice instanceof Invoice) {
                $invoice = Invoice::with(['customer', 'company', 'currency', 'emailLogs'])
                    ->where('unique_hash', $invoice)
                    ->orWhere('id', $invoice)
                    ->firstOrFail();
            } else {
                $invoice->load(['customer', 'company', 'currency', 'emailLogs']);
            }
            
            // Check if invoice is already paid
            if ($invoice->paid_status === 'PAID') {
                return redirect()->back()->with('error', 'This invoice has already been paid.');
            }

            // Get the latest email log token for redirect URLs
            $emailLogToken = $invoice->emailLogs()->latest()->first()->token ?? $invoice->unique_hash;

            // Initialize Stripe
            $stripeSecret = config('services.stripe.secret');
            if (!$stripeSecret) {
                \Log::error('Stripe secret key not configured');
                return redirect()->back()->with('error', 'Payment processing is not configured. Please contact support.');
            }
            Stripe::setApiKey($stripeSecret);

            // Ensure currency is loaded
            if (!$invoice->currency) {
                \Log::error('Invoice currency not loaded for invoice: ' . $invoice->id);
                return redirect()->back()->with('error', 'Invoice currency information is missing.');
            }

            // Convert amount to cents for Stripe (Stripe requires amounts in smallest currency unit)
            // Zero-decimal currencies (JPY, KRW, etc.) don't need conversion
            $currencyCode = strtolower($invoice->currency->code);
            $zeroDecimalCurrencies = ['jpy', 'krw', 'clp', 'vnd', 'xof', 'xaf', 'bif', 'djf', 'gnf', 'kmf', 'mga', 'rwf', 'xpf', 'vuv', 'ugx'];
            $amountInCents = in_array($currencyCode, $zeroDecimalCurrencies) 
                ? (int)$invoice->due_amount 
                : (int)($invoice->due_amount * 100);

            // Create Stripe checkout session
            // Payment methods: card (includes Apple Pay/Google Pay), link (Stripe 1-click), cashapp, us_bank_account (ACH)
            $session = StripeSession::create([
                'payment_method_types' => ['card', 'link', 'cashapp', 'us_bank_account'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currencyCode,
                        'product_data' => [
                            'name' => 'Invoice #' . $invoice->invoice_number,
                            'description' => 'Payment for ' . $invoice->company->name,
                        ],
                        'unit_amount' => $amountInCents,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => url("/customer/invoices/view/{$emailLogToken}?payment=success"),
                'cancel_url' => url("/customer/invoices/view/{$emailLogToken}?payment=cancelled"),
                'client_reference_id' => $invoice->id,
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'company_id' => $invoice->company_id,
                    'customer_id' => $invoice->customer_id,
                ],
            ]);

            // Create a pending transaction
            Transaction::createTransaction([
                'transaction_id' => $session->id,
                'type' => 'stripe',
                'status' => Transaction::PENDING,
                'transaction_date' => now(),
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
            ]);

            // Redirect to Stripe Checkout
            return redirect()->away($session->url);

        } catch (\Exception $e) {
            \Log::error('Stripe checkout error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Unable to process payment. Please try again.');
        }
    }

    /**
     * Handle Stripe webhook events
     */
    public function handleWebhook(Request $request)
    {
        try {
            // Verify webhook signature
            $payload = $request->getContent();
            $sig_header = $request->header('Stripe-Signature');
            $endpoint_secret = config('services.stripe.webhook.secret');

            if ($endpoint_secret) {
                try {
                    $event = \Stripe\Webhook::constructEvent(
                        $payload,
                        $sig_header,
                        $endpoint_secret
                    );
                } catch (\UnexpectedValueException $e) {
                    return response()->json(['error' => 'Invalid payload'], 400);
                } catch (\Stripe\Exception\SignatureVerificationException $e) {
                    return response()->json(['error' => 'Invalid signature'], 400);
                }
            } else {
                $event = json_decode($payload, true);
            }

            // Handle the event
            if ($event['type'] === 'checkout.session.completed') {
                $session = $event['data']['object'];
                
                // Get invoice from metadata
                $invoice_id = $session['metadata']['invoice_id'] ?? $session['client_reference_id'];
                
                if ($invoice_id) {
                    $this->fulfillPayment($invoice_id, $session);
                }
            }

            return response()->json(['received' => true]);

        } catch (\Exception $e) {
            \Log::error('Stripe webhook error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark invoice as paid and create payment record
     */
    private function fulfillPayment($invoice_id, $session)
    {
        try {
            $invoice = Invoice::with(['company', 'customer'])->findOrFail($invoice_id);

            // Find or create Stripe payment method
            $paymentMethod = PaymentMethod::firstOrCreate(
                ['name' => 'Stripe', 'company_id' => $invoice->company_id],
                ['name' => 'Stripe', 'company_id' => $invoice->company_id]
            );

            // Update transaction to success
            $transaction = Transaction::where('transaction_id', $session['id'])->first();
            if ($transaction) {
                $transaction->completeTransaction();
            } else {
                // Create transaction if it doesn't exist
                $transaction = Transaction::createTransaction([
                    'transaction_id' => $session['id'],
                    'type' => 'stripe',
                    'status' => Transaction::SUCCESS,
                    'transaction_date' => now(),
                    'company_id' => $invoice->company_id,
                    'invoice_id' => $invoice->id,
                ]);
            }

            // Create payment record using the transaction
            Payment::generatePayment($transaction);

            \Log::info("Payment fulfilled for invoice #{$invoice->invoice_number}");

        } catch (\Exception $e) {
            \Log::error('Error fulfilling payment: ' . $e->getMessage());
            throw $e;
        }
    }
}

