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
        // First, try to check the database directly (more reliable than file)
        try {
            // Check if settings table exists before querying
            if (\Schema::hasTable('settings')) {
                $profileComplete = \DB::table('settings')
                    ->where('option', 'profile_complete')
                    ->value('value');
                
                if ($profileComplete === 'COMPLETED') {
                    // Installation is complete - recreate the file if missing and continue
                    if (!\Storage::disk('local')->has('database_created')) {
                        \Storage::disk('local')->put('database_created', 'database_created');
                    }
                    return $next($request);
                }
                
                // Settings table exists but installation not complete
                return redirect('/installation');
            }
        } catch (\Exception $e) {
            // Database connection failed or tables don't exist yet
            \Log::debug('InstallationMiddleware: Database not ready - ' . $e->getMessage());
        }

        // Fallback: check for database_created file
        // If file exists, database might be ready but settings table doesn't exist yet
        if (!\Storage::disk('local')->has('database_created')) {
            return redirect('/installation');
        }

        // File exists but database check failed - might be in transition
        // Allow through but log it
        \Log::debug('InstallationMiddleware: database_created file exists but database check failed');
        return $next($request);
    }
}
