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
        $diag = [
            'email' => $email,
            'user_count' => User::count(),
            'first_user' => User::first()?->email,
            'all_emails' => User::pluck('email')->toArray(),
            'db_host_config' => config('database.connections.mysql.host'),
            'db_name_config' => config('database.connections.mysql.database'),
            'db_user_config' => config('database.connections.mysql.username'),
            'db_host_env' => env('DB_HOST'),
            'db_name_env' => env('DB_DATABASE'),
            'db_user_env' => env('DB_USERNAME'),
            'db_host_getenv' => getenv('DB_HOST'),
            'db_name_getenv' => getenv('DB_DATABASE'),
            'live_db_name' => \Illuminate\Support\Facades\DB::connection()->select('SELECT DATABASE() AS db')[0]->db ?? '?',
            'user_found' => $user ? 'id=' . $user->id : 'null',
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
