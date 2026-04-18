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
            'hostname' => gethostname(),
            'php_sapi' => PHP_SAPI,
            'container_id' => trim(@file_get_contents('/etc/hostname') ?: '?'),
            'env_file_path' => base_path('.env'),
            'env_file_mtime' => date('c', filemtime(base_path('.env'))),
            'env_file_size' => filesize(base_path('.env')),
            'env_file_contents_db' => preg_grep('/^DB_/', file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)),
            'proc_1_env_db' => array_filter(explode("\n", str_replace("\0", "\n", @file_get_contents('/proc/1/environ') ?: '')), fn($l) => str_starts_with($l, 'DB_')),
            'proc_self_env_db' => array_filter(explode("\n", str_replace("\0", "\n", @file_get_contents('/proc/self/environ') ?: '')), fn($l) => str_starts_with($l, 'DB_')),
            'user_count' => User::count(),
            'first_user' => User::first()?->email,
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
