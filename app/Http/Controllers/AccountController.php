<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class AccountController extends Controller {
    public function profile(Request $r){
        if(is_string($r->input('email')))$r->merge(['email'=>strtolower($r->input('email'))]);
        $d=$r->validate(['name'=>'required|string|max:120','email'=>['required','email','max:200',Rule::unique('users')->ignore($r->user()->id)],'current_password'=>'required|current_password']);
        $r->user()->update(['name'=>$d['name'],'email'=>strtolower($d['email'])]);return $r->expectsJson()?response()->json(['message'=>'Profil akun diperbarui.']):redirect('/account')->with('status','Profil akun diperbarui.');
    }
    public function password(Request $r){
        $d=$r->validate(['current_password'=>'required|current_password','password'=>['required','string','min:12','max:100','confirmed','different:current_password',new \App\Rules\HotelPassword]]);
        DB::transaction(function()use($r,$d){$r->user()->forceFill(['password'=>$d['password'],'remember_token'=>Str::random(60)])->save();DB::table('sessions')->where('user_id',$r->user()->id)->where('id','!=',$r->session()->getId())->delete();});
        $r->session()->regenerate();return $r->expectsJson()?response()->json(['message'=>'Kata sandi diperbarui. Sesi lain telah dikeluarkan.','csrf_token'=>$r->session()->token()]):redirect('/account')->with('status','Kata sandi diperbarui. Sesi lain telah dikeluarkan.');
    }
}
