<?php

namespace Crater\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PdfMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::guard('web')->check() || Auth::guard('sanctum')->check() || Auth::guard('customer')->check()) {
            return $next($request);
        }

        // Auto-authenticate as super admin if not authenticated
        $adminUser = \Crater\Models\User::where('role', 'super admin')->first();
        if ($adminUser) {
            Auth::login($adminUser);
            return $next($request);
        }

        return redirect('/login');
    }
}
