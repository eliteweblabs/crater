<?php

namespace Crater\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceStatefulRequests
{
    /**
     * Force all requests to be considered stateful for Sanctum.
     * This bypasses domain checking in Railway environment.
     */
    public function handle(Request $request, Closure $next)
    {
        // Force Sanctum to treat all requests as stateful
        config(['sanctum.stateful' => ['*']]);
        
        return $next($request);
    }
}

