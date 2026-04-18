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
            'pw_len' => strlen((string) $password),
            'user_found' => $user ? 'id=' . $user->id : 'null',
            'user_email_db' => $user?->email,
            'user_pw_prefix' => $user ? substr($user->password, 0, 10) : null,
            'hash_check' => $user ? Hash::check((string) $password, (string) $user->password) : null,
            'auth_attempt_default' => Auth::attempt(['email' => $email, 'password' => $password], false),
            'auth_attempt_web' => Auth::guard('web')->attempt(['email' => $email, 'password' => $password], false),
            'guard_class' => get_class($this->guard()),
            'guard_provider' => get_class($this->guard()->getProvider()),
            'guard_provider_model' => $this->guard()->getProvider()->getModel(),
            'credentials_method' => $this->credentials($request),
            'username_method' => $this->username(),
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
