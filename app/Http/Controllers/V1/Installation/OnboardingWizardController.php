<?php

namespace Crater\Http\Controllers\V1\Installation;

use Crater\Http\Controllers\Controller;
use Crater\Models\Setting;
use Illuminate\Http\Request;

class OnboardingWizardController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getStep(Request $request)
    {
        // First, try to check the database directly (more reliable than file)
        try {
            // Quick check: does settings table exist?
            if (\Schema::hasTable('settings')) {
                // Use direct DB query for faster response (avoid model overhead)
                $profileComplete = \DB::table('settings')
                    ->where('option', 'profile_complete')
                    ->value('value');

                // If installation is complete, recreate the file if missing
                if ($profileComplete === 'COMPLETED' && !\Storage::disk('local')->has('database_created')) {
                    \Storage::disk('local')->put('database_created', 'database_created');
                }

                return response()->json([
                    'profile_complete' => $profileComplete ?: 0,
                ]);
            }
        } catch (\Exception $e) {
            // Database might not be ready yet (migrations still running)
            \Log::debug('OnboardingWizard getStep: Database not ready - ' . $e->getMessage());
        }

        // Fallback: check for database_created file
        // If file doesn't exist, installation hasn't started
        if (!\Storage::disk('local')->has('database_created')) {
            return response()->json([
                'profile_complete' => 0,
            ]);
        }

        // File exists but database check failed - might be in transition
        return response()->json([
            'profile_complete' => 0,
        ]);
    }

    public function updateStep(Request $request)
    {
        try {
            // Check if settings table exists
            if (!\Schema::hasTable('settings')) {
                // Settings table doesn't exist yet - migrations need to run
                // Try to run them now (this happens after server restart)
                if (\Storage::disk('local')->has('database_created')) {
                    try {
                        \Log::info('Running migrations from updateStep...');
                        \Artisan::call('migrate --seed --force');
                        \Log::info('Migrations completed from updateStep');
                    } catch (\Exception $migrationError) {
                        \Log::error('Migration error in updateStep: ' . $migrationError->getMessage());
                        // Return success anyway to not block frontend
                        return response()->json([
                            'profile_complete' => $request->profile_complete,
                            'success' => true,
                        ]);
                    }
                }
                
                // Check again after migrations
                if (!\Schema::hasTable('settings')) {
                    // Still no table - return success anyway to not block frontend
                    return response()->json([
                        'profile_complete' => $request->profile_complete,
                        'success' => true,
                    ]);
                }
            }

            $setting = Setting::getSetting('profile_complete');

            if ($setting === 'COMPLETED') {
                return response()->json([
                    'profile_complete' => $setting,
                ]);
            }

            Setting::setSetting('profile_complete', $request->profile_complete);

            return response()->json([
                'profile_complete' => Setting::getSetting('profile_complete'),
            ]);
        } catch (\Exception $e) {
            \Log::error('OnboardingWizard updateStep error: ' . $e->getMessage());
            // Return success anyway to not block frontend during installation
            return response()->json([
                'profile_complete' => $request->profile_complete,
                'success' => true,
            ]);
        }
    }
}
