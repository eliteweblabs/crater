<?php

namespace Crater\Http\Controllers\V1\Customer\Payment;

use Crater\Http\Controllers\Controller;
use Crater\Models\Invoice;
use Crater\Models\Payment;
use Crater\Models\PaymentMethod;
use Crater\Models\Transaction;
use Crater\Services\ContactApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

            // Self-heal any drift between invoice.due_amount and
            // (total - sum(payments)) BEFORE we read due_amount to tell Stripe
            // what to charge. Without this we can either undercharge a
            // partially-paid invoice or — worse — mark it PAID for less than
            // the total. See Invoice::recomputeFromPayments() for the full
            // explanation.
            $invoice->recomputeFromPayments();

            // Check if invoice is already paid (after reconciliation, so we
            // don't reject a customer who's trying to pay an invoice whose
            // stale paid_status is wrong in either direction).
            if ($invoice->paid_status === 'PAID') {
                return response()->json(['error' => 'Invoice is already paid'], 400);
            }

            // Initialize Stripe
            Stripe::setApiKey(config('services.stripe.secret'));

            // Crater stores amounts in cents — pass directly to Stripe
            $currencyCode = strtolower($invoice->currency->code);
            $amountInCents = (int)$invoice->due_amount;

            // Create Stripe checkout session
            // Payment methods: card (includes Apple Pay/Google Pay), link (Stripe 1-click), cashapp, us_bank_account (ACH)
            $session = StripeSession::create($this->buildCheckoutSessionParams($invoice, [
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
            ]));

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
     * Create an embedded Stripe checkout session for public invoice links
     * Returns the client_secret for embedding the checkout form on the page
     */
    public function createEmbeddedCheckoutSession(Request $request, Invoice $invoice)
    {
        try {
            // Load relationships (currency may not be loaded from the public route)
            $invoice->loadMissing(['customer', 'company', 'currency']);

            // Self-heal due_amount / paid_status from the canonical
            // sum(payments) before we charge the customer. See
            // Invoice::recomputeFromPayments() for why this matters.
            $invoice->recomputeFromPayments();

            // Check if invoice is already paid or has nothing due
            if ($invoice->paid_status === 'PAID') {
                return response()->json(['error' => 'Invoice is already paid'], 400);
            }

            // Initialize Stripe
            $stripeSecret = config('services.stripe.secret');
            if (!$stripeSecret) {
                \Log::error('Stripe secret key not configured');
                return response()->json(['error' => 'Payment processing is not configured'], 500);
            }
            Stripe::setApiKey($stripeSecret);

            // Crater stores amounts in cents — pass directly to Stripe
            $currencyCode = strtolower($invoice->currency->code ?? 'usd');
            // If due_amount is 0 or corrupt, fall back to the invoice total
            $amountInCents = (int)$invoice->due_amount > 0
                ? (int)$invoice->due_amount
                : (int)$invoice->total;

            // Create Stripe embedded checkout session
            $session = StripeSession::create($this->buildCheckoutSessionParams($invoice, [
                'ui_mode' => 'embedded',
                'payment_method_types' => ['card', 'cashapp', 'us_bank_account'],
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
                'return_url' => url("/invoices/{$invoice->unique_hash}?payment=success&session_id={CHECKOUT_SESSION_ID}"),
                'client_reference_id' => $invoice->id,
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'company_id' => $invoice->company_id,
                    'customer_id' => $invoice->customer_id,
                ],
            ]));

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
                'clientSecret' => $session->client_secret,
            ]);

        } catch (\Exception $e) {
            \Log::error('Stripe embedded checkout error: ' . $e->getMessage());
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
                    ->firstOrFail();
            } else {
                $invoice->load(['customer', 'company', 'currency', 'emailLogs']);
            }

            // Build a safe fallback URL using the public invoice view
            $invoiceViewUrl = url("/invoices/{$invoice->unique_hash}");

            // Self-heal due_amount / paid_status from the canonical
            // sum(payments) before we charge the customer.
            $invoice->recomputeFromPayments();

            // Check if invoice is already paid
            if ($invoice->paid_status === 'PAID') {
                return redirect($invoiceViewUrl)->with('error', 'This invoice has already been paid.');
            }

            // Initialize Stripe
            $stripeSecret = config('services.stripe.secret');
            if (!$stripeSecret) {
                \Log::error('Stripe secret key not configured');
                return redirect($invoiceViewUrl)->with('error', 'Payment processing is not configured. Please contact support.');
            }
            Stripe::setApiKey($stripeSecret);

            // Ensure currency is loaded
            if (!$invoice->currency) {
                \Log::error('Invoice currency not loaded for invoice: ' . $invoice->id);
                return redirect($invoiceViewUrl)->with('error', 'Invoice currency information is missing.');
            }

            // Crater stores amounts in cents — pass directly to Stripe
            $currencyCode = strtolower($invoice->currency->code);
            $amountInCents = (int)$invoice->due_amount;

            // Allow the caller to narrow the payment method via ?method=card|bank|cashapp
            $methodParam = $request->get('method', 'all');
            $paymentMethodTypes = match($methodParam) {
                'card'    => ['card', 'link'],
                'bank'    => ['us_bank_account'],
                'cashapp' => ['cashapp'],
                default   => ['card', 'link', 'cashapp', 'us_bank_account'],
            };

            // Create Stripe checkout session
            $session = StripeSession::create($this->buildCheckoutSessionParams($invoice, [
                'payment_method_types' => $paymentMethodTypes,
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
                'success_url' => url("/invoices/{$invoice->unique_hash}?payment=success"),
                'cancel_url' => url("/invoices/{$invoice->unique_hash}?payment=cancelled"),
                'client_reference_id' => $invoice->id,
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'company_id' => $invoice->company_id,
                    'customer_id' => $invoice->customer_id,
                ],
            ]));

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
            $fallbackUrl = isset($invoiceViewUrl) ? $invoiceViewUrl : url('/');
            return redirect($fallbackUrl)->with('error', 'Unable to process payment. Please try again.');
        }
    }

    /**
     * Create a PaymentIntent for Apple Pay / Payment Request Button
     * Used by the sticky Apple Pay button on the public invoice view
     */
    public function createPaymentIntent(Request $request, Invoice $invoice)
    {
        try {
            $invoice->loadMissing(['customer', 'company', 'currency']);

            // Self-heal due_amount / paid_status from the canonical
            // sum(payments) before charging.
            $invoice->recomputeFromPayments();

            if ($invoice->paid_status === 'PAID') {
                return response()->json(['error' => 'Invoice is already paid'], 400);
            }

            $stripeSecret = config('services.stripe.secret');
            if (!$stripeSecret) {
                return response()->json(['error' => 'Payment not configured'], 500);
            }

            Stripe::setApiKey($stripeSecret);

            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount'               => (int) $invoice->due_amount,
                'currency'             => strtolower($invoice->currency->code),
                'payment_method_types' => ['card'],
                'receipt_email'        => $invoice->customer->email ?? null,
                'description'          => 'Invoice #' . $invoice->invoice_number,
                'metadata'             => [
                    'invoice_id'  => $invoice->id,
                    'company_id'  => $invoice->company_id,
                    'customer_id' => $invoice->customer_id,
                ],
            ]);

            Transaction::createTransaction([
                'transaction_id'   => $paymentIntent->id,
                'type'             => 'stripe',
                'status'           => Transaction::PENDING,
                'transaction_date' => now(),
                'company_id'       => $invoice->company_id,
                'invoice_id'       => $invoice->id,
            ]);

            return response()->json(['clientSecret' => $paymentIntent->client_secret]);

        } catch (\Exception $e) {
            \Log::error('Apple Pay PaymentIntent error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle Stripe webhook events.
     *
     * Stripe guarantees at-least-once delivery (retries on 500s and network
     * errors), so EVERY path here must be idempotent. We dedupe on the Stripe
     * event ID and on the transaction_id (Checkout Session / PaymentIntent ID)
     * so that re-delivery of the same event never creates a second Payment.
     */
    public function handleWebhook(Request $request)
    {
        try {
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
                // IMPORTANT: in production, STRIPE_WEBHOOK_SECRET must be set.
                // Without it, anyone can POST fake webhook events and mark
                // invoices paid. We log loudly and refuse to process.
                \Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not configured — rejecting to prevent spoofed events.');
                return response()->json([
                    'error' => 'Webhook signing secret not configured'
                ], 500);
            }

            $eventType = is_array($event) ? ($event['type'] ?? null) : $event->type;
            $eventId   = is_array($event) ? ($event['id'] ?? null)   : $event->id;
            $data      = is_array($event) ? ($event['data']['object'] ?? []) : $event->data->object->toArray();

            if ($eventType === 'checkout.session.completed') {
                $invoice_id = $data['metadata']['invoice_id'] ?? $data['client_reference_id'] ?? null;
                if ($invoice_id) {
                    $this->fulfillPayment($invoice_id, [
                        'id'              => $data['id'],
                        'amount_total'    => $data['amount_total'] ?? null,
                        'metadata'        => $data['metadata'] ?? [],
                        'event_id'        => $eventId,
                        'stripe_customer' => $data['customer'] ?? null,
                    ]);
                }
            } elseif ($eventType === 'payment_intent.succeeded') {
                $invoice_id = $data['metadata']['invoice_id'] ?? null;
                if ($invoice_id) {
                    $this->fulfillPayment($invoice_id, [
                        'id'              => $data['id'],
                        'amount_total'    => $data['amount'] ?? null,
                        'metadata'        => $data['metadata'] ?? [],
                        'event_id'        => $eventId,
                        'stripe_customer' => $data['customer'] ?? null,
                    ]);
                }
            } elseif ($eventType === 'customer.created') {
                // Scenario A: Stripe customer created outside of any Crater invoice
                // flow (e.g. Stripe Payment Link, direct subscription, Dashboard).
                // Resolve them against the master so they don't become an
                // identity orphan.
                $this->linkStandaloneStripeCustomer($data);
            }

            return response()->json(['received' => true]);

        } catch (\Exception $e) {
            \Log::error('Stripe webhook error: ' . $e->getMessage());
            // Return 200 for application errors so Stripe doesn't hammer us
            // with retries for a bug that retrying can't fix; but return 500
            // on deadlocks so Stripe DOES retry (which is correct).
            $code = ($e instanceof \Illuminate\Database\QueryException && str_contains($e->getMessage(), 'Deadlock')) ? 500 : 200;
            return response()->json(['error' => $e->getMessage()], $code);
        }
    }

    /**
     * Mark invoice as paid and create payment record. Fully idempotent — safe
     * to call multiple times with the same Stripe session/PI ID.
     */
    private function fulfillPayment($invoice_id, $session)
    {
        DB::transaction(function () use ($invoice_id, $session) {
            // Lock the invoice row for the duration of this fulfillment so
            // concurrent webhook deliveries for the same invoice serialize.
            $invoice = Invoice::where('id', $invoice_id)->lockForUpdate()->first();
            if (! $invoice) {
                \Log::warning("Stripe webhook: invoice {$invoice_id} not found");
                return;
            }
            $invoice->load(['company', 'customer']);

            // Idempotency guard #1: if this Stripe session/PI was already
            // turned into a SUCCESS transaction, we've already fulfilled it.
            $existing = Transaction::where('transaction_id', $session['id'])
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === Transaction::SUCCESS) {
                \Log::info("Webhook skipped — transaction {$session['id']} already fulfilled");
                return;
            }

            // Idempotency guard #2: if the invoice is already PAID we never
            // want to add more payment rows, regardless of what Stripe says.
            if ($invoice->paid_status === Invoice::STATUS_PAID) {
                \Log::info("Webhook skipped — invoice #{$invoice->invoice_number} already paid");
                if ($existing && $existing->status !== Transaction::SUCCESS) {
                    $existing->status = Transaction::SUCCESS;
                    $existing->save();
                }
                return;
            }

            $paymentMethod = PaymentMethod::firstOrCreate(
                ['name' => 'Stripe', 'company_id' => $invoice->company_id],
                ['name' => 'Stripe', 'company_id' => $invoice->company_id]
            );

            if ($existing) {
                $transaction = $existing;
            } else {
                $transaction = Transaction::createTransaction([
                    'transaction_id'   => $session['id'],
                    'type'             => 'stripe',
                    'status'           => Transaction::PENDING,
                    'transaction_date' => now(),
                    'company_id'       => $invoice->company_id,
                    'invoice_id'       => $invoice->id,
                ]);
            }

            // Use what Stripe actually collected (in cents) so partial or
            // adjusted payments don't push due_amount below zero.
            $amountCharged = $session['amount_total'] ?? $invoice->due_amount;

            Payment::generatePayment($transaction, $paymentMethod->id, $amountCharged);

            $transaction->status = Transaction::SUCCESS;
            $transaction->save();

            $this->linkStripeCustomerToMaster($invoice, $session['stripe_customer'] ?? null);

            \Log::info("Payment fulfilled for invoice #{$invoice->invoice_number} (stripe session {$session['id']}, event {$session['event_id']} )");
        });
    }

    /**
     * Common Checkout Session params: always attach the customer's email and
     * tell Stripe to always create a Customer object. This guarantees the
     * webhook will carry a stable `data.customer` we can link to the master
     * identity record. Without `customer_creation: always`, Stripe may skip
     * creating a Customer for some payment methods and we lose the link.
     */
    private function buildCheckoutSessionParams(Invoice $invoice, array $params): array
    {
        $email = $invoice->customer->email ?? null;
        if ($email && empty($params['customer_email']) && empty($params['customer'])) {
            $params['customer_email'] = $email;
        }
        if (empty($params['customer'])) {
            $params['customer_creation'] = 'always';
        }
        return $params;
    }

    /**
     * Link the auto-created Stripe customer to the master identity contact.
     * Best-effort: contact-api outages or missing contact_uid silently no-op.
     */
    private function linkStripeCustomerToMaster(Invoice $invoice, ?string $stripeCustomerId): void
    {
        if (empty($stripeCustomerId) || empty($invoice->customer) || empty($invoice->customer->contact_uid)) {
            return;
        }

        try {
            app(ContactApiClient::class)->link(
                $invoice->customer->contact_uid,
                'stripe',
                $stripeCustomerId,
                [
                    'email'      => $invoice->customer->email,
                    'invoice_id' => $invoice->id,
                    'source'     => 'checkout.session',
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('[contact-api] stripe link from invoice failed', [
                'invoice_id'        => $invoice->id,
                'stripe_customer'   => $stripeCustomerId,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle `customer.created` events that did NOT come from a Crater
     * invoice flow. Resolve against contact-api, link if a match exists,
     * otherwise create a new master contact and link.
     */
    private function linkStandaloneStripeCustomer(array $data): void
    {
        $api = app(ContactApiClient::class);
        if (!$api->isEnabled() || empty($data['id'])) {
            return;
        }

        // Skip if this Stripe customer was created moments ago by an active
        // Crater checkout flow — the checkout.session.completed handler will
        // already link it with full invoice context.
        $hasInvoiceMetadata = !empty(($data['metadata'] ?? [])['invoice_id'])
            || !empty(($data['metadata'] ?? [])['crater_invoice_id']);
        if ($hasInvoiceMetadata) {
            return;
        }

        try {
            $name  = $data['name']  ?? null;
            $email = $data['email'] ?? null;
            $phone = $data['phone'] ?? null;

            $res = $api->resolve($name, $email, $phone);
            $match = $res['match'] ?? 'none';
            $uid   = null;

            if (in_array($match, ['exact', 'likely'], true)) {
                $uid = data_get($res, 'contact.uid');
            } else {
                $created = $api->create($name, $email, $phone, null);
                $uid = data_get($created, 'contact.uid') ?? data_get($created, 'uid');
            }

            if ($uid) {
                $api->link($uid, 'stripe', $data['id'], [
                    'email'  => $email,
                    'source' => 'stripe.customer.created',
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('[contact-api] standalone stripe customer sync failed', [
                'stripe_customer' => $data['id'] ?? null,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}

