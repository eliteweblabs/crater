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
            try {
                Artisan::call('key:generate --force');
                Artisan::call('optimize:clear');
                Artisan::call('config:clear');
                Artisan::call('cache:clear');
                Artisan::call('storage:link');
                
                // Run migrations with increased timeout
                set_time_limit(300); // 5 minutes
                Artisan::call('migrate --seed --force');
            } catch (\Exception $e) {
                \Log::error('Installation migration error: ' . $e->getMessage());
                return response()->json([
                    'error' => 'migration_failed',
                    'error_message' => $e->getMessage(),
                ], 500);
            }
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
