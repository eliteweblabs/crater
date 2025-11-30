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
        if (! \Storage::disk('local')->has('database_created')) {
            return response()->json([
                'profile_complete' => 0,
            ]);
        }

        try {
            // Check if settings table exists before querying
            if (\Schema::hasTable('settings')) {
                $profileComplete = Setting::getSetting('profile_complete');
                return response()->json([
                    'profile_complete' => $profileComplete ?: 0,
                ]);
            }
        } catch (\Exception $e) {
            // Database might not be ready yet (migrations still running)
            \Log::debug('OnboardingWizard getStep: Database not ready - ' . $e->getMessage());
        }

        // Default to step 0 if database not ready
        return response()->json([
            'profile_complete' => 0,
        ]);
    }

    public function updateStep(Request $request)
    {
        try {
            // Check if settings table exists before querying
            if (!\Schema::hasTable('settings')) {
                return response()->json([
                    'profile_complete' => 0,
                    'error' => 'Database not ready - migrations may still be running',
                ], 503);
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
            return response()->json([
                'profile_complete' => 0,
                'error' => 'Database error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
