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

            // Create Stripe checkout session
            $session = StripeSession::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($invoice->currency->code),
                        'product_data' => [
                            'name' => 'Invoice #' . $invoice->invoice_number,
                            'description' => 'Payment for ' . $invoice->company->name,
                        ],
                        'unit_amount' => (int)($invoice->due_amount * 100), // Stripe expects cents
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

