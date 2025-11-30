<?php

namespace Crater\Http\Controllers\V1\Installation;

use Crater\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class FinishController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        // Mark database as created
        \Storage::disk('local')->put('database_created', 'database_created');

        // Run migrations if not already done
        try {
            if (!\Schema::hasTable('users')) {
                \Log::info('FinishController: Running migrations...');
                Artisan::call('migrate --seed --force');
                \Log::info('FinishController: Migrations completed');
            }
        } catch (\Exception $e) {
            \Log::error('FinishController: Migration error - ' . $e->getMessage());
            // Don't fail - migrations might run on next request
        }

        return response()->json(['success' => true]);
    }
}
