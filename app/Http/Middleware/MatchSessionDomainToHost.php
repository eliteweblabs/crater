<?php

namespace Crater\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MatchSessionDomainToHost
{
    /**
     * Keep session cookies on APP_URL's host and send legacy domains to the canonical URL.
     */
    public function handle(Request $request, Closure $next)
    {
        $appUrl = config('app.url');
        $canonicalHost = $appUrl ? parse_url($appUrl, PHP_URL_HOST) : null;
        $host = $request->getHost();

        if ($canonicalHost && $host && $host !== $canonicalHost && $this->shouldRedirectToCanonical($host)) {
            $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';

            return redirect()->away($scheme.'://'.$canonicalHost.$request->getRequestUri(), 301);
        }

        if ($canonicalHost) {
            config(['session.domain' => $canonicalHost]);
        } elseif ($host && $host !== 'localhost' && ! filter_var($host, FILTER_VALIDATE_IP)) {
            config(['session.domain' => $host]);
        }

        return $next($request);
    }

    protected function shouldRedirectToCanonical(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return ! str_ends_with($host, '.railway.app');
    }
}
