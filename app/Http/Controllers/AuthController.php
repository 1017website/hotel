<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak sesuai.']);
        }
        $request->session()->regenerate();
        $admin = \Illuminate\Support\Facades\DB::table('platform_admins')->where('user_id', $request->user()->id)->first();
        if ($admin) {
            if (!$admin->active) { Auth::logout(); $request->session()->invalidate(); throw ValidationException::withMessages(['email' => 'Akun admin SaaS sudah dinonaktifkan.']); }
            $request->session()->forget('url.intended');
            return redirect('/saas');
        }
        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
