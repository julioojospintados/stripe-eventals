<?php

namespace App\Http\Controllers;

use App\Services\LoginCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Accesso dei clienti: email → codice di 6 cifre → sessione Laravel. */
class LoginController
{
    public function show()
    {
        return view('auth.login');
    }

    public function send(Request $request, LoginCodeService $codes)
    {
        $email = $request->validate(['email' => ['required', 'email', 'max:254']])['email'];
        $codes->send($email, $request->ip());
        $request->session()->put('login_email', mb_strtolower(trim($email)));

        return redirect()->route('login.code');
    }

    public function code(Request $request)
    {
        return $request->session()->has('login_email') ? view('auth.code') : redirect()->route('login');
    }

    public function verify(Request $request, LoginCodeService $codes)
    {
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $email = (string) $request->session()->get('login_email');

        $user = $codes->verify($email, $code, $request->ip());
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('login_email');

        return redirect()->intended(route('account.index'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('shop.index');
    }
}
