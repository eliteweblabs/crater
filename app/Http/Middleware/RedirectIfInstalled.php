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
        if (\Storage::disk('local')->has('database_created')) {
            try {
                // Check if settings table exists before querying
                if (\Schema::hasTable('settings')) {
                    $profileComplete = Setting::getSetting('profile_complete');
                    if ($profileComplete === 'COMPLETED') {
                        return redirect('login');
                    }
                }
            } catch (\Exception $e) {
                // Database might not be ready yet, continue to installation
                \Log::debug('RedirectIfInstalled: Database not ready - ' . $e->getMessage());
            }
        }

        return $next($request);
    }
}
