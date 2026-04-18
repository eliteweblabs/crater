<?php

namespace Crater\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PdfMiddleware
{
    /**
     * Allow access to PDF routes in two ways:
     *
     *   1. The caller is authenticated (admin or customer portal) — they are
     *      already allowed to see their own invoices.
     *   2. The URL contains a public `unique_hash` route parameter (e.g.
     *      `/invoices/pdf/{invoice:unique_hash}`). The hash is a 32-character
     *      random secret generated at invoice creation and is only ever
     *      shared with the intended recipient via the email/SMS link they
     *      receive. Anyone who has the hash is authorized to view the PDF —
     *      this is the Harvest/Stripe-style magic-link flow.
     *
     * We do NOT fall back to auto-authenticating as admin.
     */
    public function handle(Request $request, Closure $next)
    {
        if (
            Auth::guard('web')->check()
            || Auth::guard('sanctum')->check()
            || Auth::guard('customer')->check()
        ) {
            return $next($request);
        }

        if ($this->hasValidUniqueHash($request)) {
            return $next($request);
        }

        // Not authenticated AND no valid unique_hash in URL — bounce to login.
        return redirect('/login');
    }

    /**
     * Returns true when the request URL targets a model-bound `unique_hash`
     * segment that resolves to a real record. Route model binding has already
     * been resolved by Laravel before middleware runs, so we can inspect the
     * route's parameters directly.
     */
    protected function hasValidUniqueHash(Request $request): bool
    {
        $route = $request->route();
        if (! $route) {
            return false;
        }

        foreach (['invoice', 'estimate', 'payment'] as $name) {
            $param = $route->parameter($name);
            if ($param && is_object($param) && ! empty($param->unique_hash)) {
                return true;
            }
        }

        return false;
    }
}
