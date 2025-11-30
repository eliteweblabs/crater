<?php

namespace Crater\Http\Middleware;

use Closure;
use Crater\Models\Setting;

class RedirectIfInstalled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // If database hasn't been created yet, allow installation
        if (!\Storage::disk('local')->has('database_created')) {
            return $next($request);
        }

        // Database exists, check if installation is complete
        try {
            // Quick check: does settings table exist?
            if (!\Schema::hasTable('settings')) {
                // Settings table doesn't exist, still installing
                return $next($request);
            }

            // Try to get profile_complete setting with timeout protection
            // Use DB::table directly to avoid model overhead
            $profileComplete = \DB::table('settings')
                ->where('option', 'profile_complete')
                ->value('value');

            if ($profileComplete === 'COMPLETED') {
                return redirect('login');
            }
        } catch (\Exception $e) {
            // Database might not be ready yet, continue to installation
            \Log::debug('RedirectIfInstalled: Database not ready - ' . $e->getMessage());
            // Don't block installation if there's any error
        }

        return $next($request);
    }
}
