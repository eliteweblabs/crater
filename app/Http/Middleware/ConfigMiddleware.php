<?php

namespace Crater\Http\Middleware;

use Closure;
use Crater\Models\FileDisk;

class ConfigMiddleware
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
        // Only configure file disk if database is ready
        if (!\Storage::disk('local')->has('database_created')) {
            return $next($request);
        }

        try {
            // Check if file_disks table exists before querying
            if (!\Schema::hasTable('file_disks')) {
                // Table doesn't exist yet, migrations still running
                return $next($request);
            }

            if ($request->has('file_disk_id')) {
                $file_disk = FileDisk::find($request->file_disk_id);
            } else {
                $file_disk = FileDisk::whereSetAsDefault(true)->first();
            }

            if ($file_disk) {
                $file_disk->setConfig();
            }
        } catch (\Exception $e) {
            // Database might not be ready yet, don't block the request
            \Log::debug('ConfigMiddleware: Database not ready - ' . $e->getMessage());
            // Continue without file disk config
        }

        return $next($request);
    }
}
