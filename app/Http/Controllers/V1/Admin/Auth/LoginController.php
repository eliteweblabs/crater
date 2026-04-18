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
        throw new \RuntimeException('LOGIN_METHOD_HIT keys=' . json_encode(array_keys($request->all())));
    }

    protected function attemptLogin(Request $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $user = User::where('email', $email)->first();
        @file_put_contents('/tmp/login_debug.log', date('c').' LOGIN_DEBUG ' . json_encode([
            'email_received' => $email,
            'email_len' => strlen((string) $email),
            'password_received_len' => strlen((string) $password),
            'password_received_hex' => bin2hex((string) $password),
            'user_found' => $user ? 'id='.$user->id : 'null',
            'user_email_db' => $user?->email,
            'user_pw_prefix' => $user ? substr($user->password, 0, 10) : null,
            'hash_check' => $user ? Hash::check((string) $password, (string) $user->password) : null,
            'auth_attempt_default' => Auth::attempt(['email' => $email, 'password' => $password], false),
            'auth_attempt_web' => Auth::guard('web')->attempt(['email' => $email, 'password' => $password], false),
            'request_keys' => array_keys($request->all()),
            'request_only' => array_keys($request->only(['email', 'password'])),
            'credentials_method' => $this->credentials($request),
            'username_method' => $this->username(),
        ]) . "\n", FILE_APPEND);
        return $this->guard()->attempt(
            $this->credentials($request), $request->boolean('remember')
        );
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
