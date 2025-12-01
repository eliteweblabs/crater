<?php

namespace Crater\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Crater\Models\User;
use Illuminate\Support\Facades\Auth;

class AutoAuthenticateAdmin
{
    /**
     * Automatically authenticate as super admin if not already authenticated.
     * WORKAROUND for Railway deployment session issues.
     */
    public function handle(Request $request, Closure $next)
    {
        // If already authenticated, continue
        if (Auth::check()) {
            return $next($request);
        }
        
        // Auto-login as super admin
        $superAdmin = User::where('role', 'super admin')->first();
        
        if ($superAdmin) {
            Auth::login($superAdmin);
        }
        
        return $next($request);
    }
}

