<?php

namespace Crater\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MatchSessionDomainToHost
{
    /**
     * Scope session cookies to the host the user actually uses in the browser.
     * APP_URL in Railway can lag behind a new custom domain (e.g. ap.reave.app vs ap.rekko.studio).
     */
    public function handle(Request $request, Closure $next)
    {
        $host = $request->getHost();

        if ($host && $host !== 'localhost' && ! filter_var($host, FILTER_VALIDATE_IP)) {
            config(['session.domain' => $host]);
        }

        return $next($request);
    }
}
