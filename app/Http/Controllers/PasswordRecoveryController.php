<?php

namespace App\Http\Controllers;

use App\Rules\HotelPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordRecoveryController extends Controller
{
    public function form() { return view('saas.recovery',['reset'=>false]); }
    public function send(Request $r)
    {
        $d=$r->validate(['email'=>'required|email|max:200']);
        Password::sendResetLink(['email'=>strtolower($d['email'])]);
        return back()->with('status','Jika email terdaftar, tautan pemulihan akan dikirim. Periksa kotak masuk atau hubungi pengelola bila layanan email belum tersedia.');
    }
    public function resetForm(Request $r,string $token) { return view('saas.recovery',['reset'=>true,'token'=>$token,'email'=>$r->query('email','')]); }
    public function reset(Request $r)
    {
        $d=$r->validate(['token'=>'required|string','email'=>'required|email|max:200','password'=>['required','string','min:12','confirmed',new HotelPassword]]);
        $status=Password::reset([...$d,'email'=>strtolower($d['email'])],function($user,$password){DB::transaction(function()use($user,$password){$user->forceFill(['password'=>$password,'remember_token'=>Str::random(60)])->save();DB::table('sessions')->where('user_id',$user->id)->delete();});event(new PasswordReset($user));});
        if($status!==Password::PASSWORD_RESET)return back()->withErrors(['email'=>'Tautan pemulihan tidak valid atau kedaluwarsa. Minta tautan baru.'])->onlyInput('email');
        return redirect('/login')->with('status','Password diperbarui. Silakan masuk kembali.');
    }
}
