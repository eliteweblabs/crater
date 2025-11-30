<?php

namespace Crater\Http\Middleware;

use Closure;
use Crater\Models\Setting;

class InstallationMiddleware
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
        if (! \Storage::disk('local')->has('database_created')) {
            return redirect('/installation');
        }

        if (\Storage::disk('local')->has('database_created')) {
            try {
                // Check if settings table exists before querying
                if (\Schema::hasTable('settings')) {
                    $profileComplete = Setting::getSetting('profile_complete');
                    if ($profileComplete !== 'COMPLETED') {
                        return redirect('/installation');
                    }
                } else {
                    // Settings table doesn't exist yet, still installing
                    return redirect('/installation');
                }
            } catch (\Exception $e) {
                // Database might not be ready yet, continue installation
                \Log::debug('InstallationMiddleware: Database not ready - ' . $e->getMessage());
                return redirect('/installation');
            }
        }

        return $next($request);
    }
}
