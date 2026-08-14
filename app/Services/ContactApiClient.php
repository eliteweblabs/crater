<?php

namespace Crater\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin best-effort HTTP client for the contact-api master identity service.
 *
 * Every call is wrapped so a contact-api outage never breaks the caller —
 * methods return null/false on failure and log a warning. If the service is
 * disabled in config (no URL set), every method short-circuits to a no-op.
 */
class ContactApiClient
{
    public function isEnabled(): bool
    {
        return (bool) config('contact_api.enabled') && !empty(config('contact_api.url'));
    }

    /**
     * GET /api/contacts/:uid — full contact including portal link metadata.
     *
     * Returns the contact object or null. $timeoutSeconds overrides the
     * default client timeout (OG cards need a bit more room when iconData
     * is embedded as base64).
     */
    public function get(string $uid, ?int $timeoutSeconds = null): ?array
    {
        if (!$this->isEnabled() || $uid === '') {
            return null;
        }

        try {
            $client = $this->client();
            if ($timeoutSeconds !== null) {
                $client = $client->timeout($timeoutSeconds);
            }

            $res = $client->get('/api/contacts/'.rawurlencode($uid));
            if (!$res->successful()) {
                Log::warning('[contact-api] non-2xx', [
                    'method' => 'GET',
                    'path'   => '/api/contacts/'.$uid,
                    'status' => $res->status(),
                    'body'   => mb_substr((string) $res->body(), 0, 500),
                ]);
                return null;
            }

            $json = $res->json();
            $contact = is_array($json) ? ($json['contact'] ?? null) : null;
            return is_array($contact) ? $contact : null;
        } catch (\Throwable $e) {
            Log::warning('[contact-api] request failed', [
                'method' => 'GET',
                'path'   => '/api/contacts/'.$uid,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * POST /api/contacts/resolve
     *
     * Returns the parsed body or null on failure. Response shape:
     *   { match: "exact"|"likely"|"possible"|"none", contact?: {...}, candidates?: [...] }
     */
    public function resolve(?string $name, ?string $email, ?string $phone): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if (empty($name) && empty($email) && empty($phone)) {
            return ['match' => 'none'];
        }

        $body = array_filter([
            'name'  => $name,
            'email' => $email,
            'phone' => $phone,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->postJson('/api/contacts/resolve', $body);
    }

    /**
     * POST /api/contacts — returns the created contact body or null.
     */
    public function create(?string $name, ?string $email, ?string $phone, ?string $company = null): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if (empty($name) && empty($email) && empty($phone)) {
            return null;
        }

        $body = array_filter([
            'name'    => $name ?: $email ?: $phone,
            'email'   => $email,
            'phone'   => $phone,
            'company' => $company,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->postJson('/api/contacts', $body);
    }

    /**
     * PATCH /api/contacts/:uid — push field updates upstream.
     */
    public function update(string $uid, array $fields): ?array
    {
        if (!$this->isEnabled() || empty($uid)) {
            return null;
        }

        $body = array_filter($fields, fn ($v) => $v !== null && $v !== '');
        if (empty($body)) {
            return null;
        }

        return $this->sendJson('patch', "/api/contacts/{$uid}", $body);
    }

    /**
     * POST /api/contacts/:uid/link — register a downstream system linkage.
     */
    public function link(string $uid, string $system, $externalId, array $metadata = []): bool
    {
        if (!$this->isEnabled() || empty($uid) || empty($externalId)) {
            return false;
        }

        $body = [
            'system'     => $system,
            'externalId' => (string) $externalId,
        ];
        if (!empty($metadata)) {
            $body['metadata'] = $metadata;
        }

        $res = $this->postJson("/api/contacts/{$uid}/link", $body);
        return is_array($res) && ($res['success'] ?? false);
    }

    private function client(): PendingRequest
    {
        $client = Http::baseUrl(rtrim((string) config('contact_api.url'), '/'))
            ->timeout((int) config('contact_api.timeout', 3))
            ->acceptJson()
            ->asJson();

        $key = config('contact_api.key');
        if (!empty($key)) {
            $client = $client->withHeaders(['X-API-Key' => $key]);
        }

        return $client;
    }

    private function postJson(string $path, array $body): ?array
    {
        return $this->sendJson('post', $path, $body);
    }

    private function sendJson(string $method, string $path, array $body): ?array
    {
        try {
            $res = $this->client()->{$method}($path, $body);
            if (!$res->successful()) {
                Log::warning('[contact-api] non-2xx', [
                    'method' => strtoupper($method),
                    'path'   => $path,
                    'status' => $res->status(),
                    'body'   => mb_substr((string) $res->body(), 0, 500),
                ]);
                return null;
            }
            return $res->json();
        } catch (\Throwable $e) {
            Log::warning('[contact-api] request failed', [
                'method' => strtoupper($method),
                'path'   => $path,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }
}
