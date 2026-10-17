<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function form(): View
    {
        return view('login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $credenciais = $request->validate(['email' => 'required|email', 'password' => 'required']);

        if (Auth::attempt($credenciais, true)) {
            $request->session()->regenerate();

            return redirect()->intended('/');
        }

        return back()->withErrors(['email' => 'E-mail ou senha incorretos.'])->onlyInput('email');
    }

    public function sair(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
