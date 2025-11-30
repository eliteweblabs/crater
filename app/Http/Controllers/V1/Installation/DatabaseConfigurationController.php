<?php

namespace Crater\Http\Controllers\V1\Installation;

use Crater\Http\Controllers\Controller;
use Crater\Http\Requests\DatabaseEnvironmentRequest;
use Crater\Space\EnvironmentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class DatabaseConfigurationController extends Controller
{
    /**
     * @var EnvironmentManager
     */
    protected $EnvironmentManager;

    /**
     * @param EnvironmentManager $environmentManager
     */
    public function __construct(EnvironmentManager $environmentManager)
    {
        $this->environmentManager = $environmentManager;
    }

    /**
     *
     * @param DatabaseEnvironmentRequest $request
     */
    public function saveDatabaseEnvironment(DatabaseEnvironmentRequest $request)
    {
        Artisan::call('config:clear');
        Artisan::call('cache:clear');

        $results = $this->environmentManager->saveDatabaseVariables($request);

        if (array_key_exists("success", $results)) {
            // Do quick setup tasks first
            try {
                Artisan::call('key:generate --force');
                Artisan::call('optimize:clear');
                Artisan::call('config:clear');
                Artisan::call('cache:clear');
                Artisan::call('storage:link');
            } catch (\Exception $e) {
                \Log::error('Installation setup error: ' . $e->getMessage());
                return response()->json([
                    'error' => 'setup_failed',
                    'error_message' => $e->getMessage(),
                ], 500);
            }
            
            // Prepare response
            $response = response()->json($results);
            
            // Send response immediately to prevent Railway timeout
            if (function_exists('fastcgi_finish_request')) {
                // FastCGI - send response, then continue
                fastcgi_finish_request();
            } else {
                // Other SAPI - close connection
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $response->send();
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
            }
            
            // Now run migrations in background (after response sent)
            try {
                set_time_limit(300); // 5 minutes
                ignore_user_abort(true);
                Artisan::call('migrate --seed --force');
                \Log::info('Installation migrations completed');
            } catch (\Exception $e) {
                \Log::error('Installation migration error: ' . $e->getMessage());
            }
            
            // Return response (already sent, but Laravel expects return)
            return $response;
        }

        return response()->json($results);
    }

    public function getDatabaseEnvironment(Request $request)
    {
        $databaseData = [];

        switch ($request->connection) {
            case 'sqlite':
                $databaseData = [
                    'database_connection' => 'sqlite',
                    'database_name' => database_path('database.sqlite'),
                ];

                break;

            case 'pgsql':
                $databaseData = [
                    'database_connection' => 'pgsql',
                    'database_host' => '127.0.0.1',
                    'database_port' => 5432,
                ];

                break;

            case 'mysql':
                $databaseData = [
                    'database_connection' => 'mysql',
                    'database_host' => '127.0.0.1',
                    'database_port' => 3306,
                ];

                break;

        }


        return response()->json([
            'config' => $databaseData,
            'success' => true,
        ]);
    }
}
