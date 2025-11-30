<?php

namespace Crater\Http\Controllers\V1\Installation;

use Auth;
use Crater\Http\Controllers\Controller;
use Crater\Models\User;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        try {
            // Check if users table exists
            if (!\Schema::hasTable('users')) {
                \Log::error('LoginController: Users table does not exist');
                return response()->json([
                    'success' => false,
                    'error' => 'Database not ready - users table missing'
                ], 500);
            }

            $user = User::where('role', 'super admin')->first();
            
            if (!$user) {
                \Log::error('LoginController: No super admin user found - seeders may not have run');
                // Try to run seeders
                try {
                    \Artisan::call('db:seed --force');
                    $user = User::where('role', 'super admin')->first();
                } catch (\Exception $seedError) {
                    \Log::error('LoginController: Seeder error - ' . $seedError->getMessage());
                }
            }

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'No admin user found. Please check if database seeders ran correctly.'
                ], 500);
            }

            Auth::login($user);

            return response()->json([
                'success' => true,
                'user' => $user,
                'company' => $user->companies()->first()
            ]);
        } catch (\Exception $e) {
            \Log::error('LoginController error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
