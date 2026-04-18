<?php

namespace Crater\Http\Controllers\V1\Admin\Auth;

use Crater\Http\Controllers\Controller;
use Crater\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Crater\Models\User;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $user = User::where('email', $email)->first();
        $envFiles = glob(base_path('.env*'));
        $diag = [
            'email' => $email,
            'user_count' => User::count(),
            'all_emails' => User::pluck('email')->toArray(),
            'live_db_host' => \Illuminate\Support\Facades\DB::connection()->getPdo()->getAttribute(\PDO::ATTR_CONNECTION_STATUS),
            'live_db_name' => \Illuminate\Support\Facades\DB::connection()->select('SELECT DATABASE() AS db, USER() AS u, @@hostname AS h')[0],
            'env_DB_HOST' => env('DB_HOST'),
            'getenv_DB_HOST' => getenv('DB_HOST'),
            '_ENV_DB_HOST' => $_ENV['DB_HOST'] ?? '(unset)',
            '_SERVER_DB_HOST' => $_SERVER['DB_HOST'] ?? '(unset)',
            '_ENV_DB_DATABASE' => $_ENV['DB_DATABASE'] ?? '(unset)',
            '_SERVER_DB_DATABASE' => $_SERVER['DB_DATABASE'] ?? '(unset)',
            'env_files_found' => $envFiles,
            'env_file_used' => app()->environmentFile(),
            'env_file_contents_db' => preg_grep('/^DB_/', file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)),
            'app_env' => env('APP_ENV'),
        ];
        throw new \RuntimeException('LOGIN_DIAG ' . json_encode($diag));
    }

    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }
}
