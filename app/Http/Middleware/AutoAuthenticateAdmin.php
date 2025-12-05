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
        
        // Skip if database tables don't exist yet (during installation)
        try {
            if (!\Schema::hasTable('users')) {
                return $next($request);
            }
        } catch (\Exception $e) {
            // Database connection failed or tables don't exist
            return $next($request);
        }
        
        // Auto-login as super admin
        try {
            $superAdmin = User::where('role', 'super admin')->first();
            
            if ($superAdmin) {
                Auth::login($superAdmin);
            }
        } catch (\Exception $e) {
            // Table doesn't exist or query failed - continue without auto-login
        }
        
        return $next($request);
    }
}

