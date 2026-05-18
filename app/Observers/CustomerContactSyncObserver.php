<?php

namespace Crater\Observers;

use Crater\Models\Customer;
use Crater\Services\ContactApiClient;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors Crater Customer lifecycle into the contact-api master identity store.
 *
 * Design:
 *   - All calls are best-effort: failures never block customer create/update/delete.
 *   - On `created`: resolve by (email, phone, name); link to an existing master
 *     contact on exact/likely match, otherwise create a new master contact.
 *     "Possible" fuzzy matches are NOT auto-linked — we log them for manual
 *     review and create a fresh contact to avoid silent identity collisions.
 *   - On `updated`: if we have a contact_uid, push changed identity fields up.
 *   - On `deleted`: remove the (crater, customer_id) link. We do NOT archive
 *     the master contact — the same person may exist in Cal.com / Stripe / etc.
 */
class CustomerContactSyncObserver
{
    public function __construct(private ContactApiClient $api) {}

    public function created(Customer $customer): void
    {
        if (!$this->api->isEnabled()) {
            return;
        }
        if (!empty($customer->contact_uid)) {
            return;
        }

        try {
            $uid = $this->resolveOrCreate($customer);
            if (!$uid) {
                return;
            }
            $this->linkAndStore($customer, $uid);
        } catch (\Throwable $e) {
            Log::warning('[contact-api] sync on created failed', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    public function updated(Customer $customer): void
    {
        if (!$this->api->isEnabled()) {
            return;
        }

        try {
            // Backfill linkage for customers that existed before this wiring landed.
            if (empty($customer->contact_uid)) {
                $uid = $this->resolveOrCreate($customer);
                if ($uid) {
                    $this->linkAndStore($customer, $uid);
                }
                return;
            }

            // Only push identity fields if any of them actually changed.
            $watched = ['name', 'email', 'phone', 'company_name'];
            $dirty = array_intersect($watched, array_keys($customer->getChanges()));
            if (empty($dirty)) {
                return;
            }

            $this->api->update($customer->contact_uid, [
                'name'    => $customer->name,
                'email'   => $customer->email,
                'phone'   => $customer->phone,
                'company' => $customer->company_name,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[contact-api] sync on updated failed', [
                'customer_id' => $customer->id,
                'contact_uid' => $customer->contact_uid,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    public function deleted(Customer $customer): void
    {
        // Currently a no-op. The contact-api does not expose an unlink-only
        // endpoint; merge/archive on the master is intentionally manual so a
        // Crater delete never silently nukes identity for other systems.
    }

    private function resolveOrCreate(Customer $customer): ?string
    {
        $resolution = $this->api->resolve(
            $customer->name,
            $customer->email,
            $customer->phone,
        );

        if (!is_array($resolution)) {
            return null;
        }

        $match = $resolution['match'] ?? 'none';

        if (in_array($match, ['exact', 'likely'], true)) {
            return data_get($resolution, 'contact.uid');
        }

        if ($match === 'possible') {
            // Don't auto-link — log so a human can review and merge.
            Log::warning('[contact-api] possible duplicate, creating new contact instead', [
                'customer_id'       => $customer->id,
                'customer_email'    => $customer->email,
                'candidate_uids'    => array_map(
                    fn ($c) => $c['uid'] ?? null,
                    $resolution['candidates'] ?? []
                ),
            ]);
        }

        $created = $this->api->create(
            $customer->name,
            $customer->email,
            $customer->phone,
            $customer->company_name,
        );

        return data_get($created, 'contact.uid') ?? data_get($created, 'uid');
    }

    private function linkAndStore(Customer $customer, string $uid): void
    {
        $this->api->link(
            $uid,
            (string) config('contact_api.system_name', 'crater'),
            $customer->id,
            ['company_id' => $customer->company_id],
        );

        // Avoid retriggering the observer.
        Customer::withoutEvents(function () use ($customer, $uid) {
            $customer->contact_uid = $uid;
            $customer->saveQuietly();
        });
    }
}
