<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionAccess;
use App\Services\SubscriptionService;
use App\Support\HotelContext;
use App\Support\PlatformContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionController extends Controller
{
    public function registerForm()
    {
        $settings=DB::table('saas_settings')->find(1);
        if(SubscriptionAccess::maintenance($settings))throw new \App\Exceptions\SubscriptionUnavailable('maintenance','Pendaftaran sementara ditutup untuk pemeliharaan.',true);
        return response()->view('saas.register',['settings'=>$settings,'plans'=>DB::table('subscription_plans')->where('published',true)->orderBy('monthly_price')->get()])->header('Cache-Control','no-store');
    }

    public function register(Request $r,SubscriptionService $service)
    {
        $settings=DB::table('saas_settings')->find(1);
        abort_unless($settings->signup_enabled&&!SubscriptionAccess::maintenance($settings),403,'Pendaftaran belum dibuka.');
        $created=$service->register((new PlatformController)->registrationData($r));
        Auth::login($created->user);$r->session()->regenerate();$r->session()->put('hotel_id',$created->hotel_id);
        return redirect('/subscription')->with('status','Akun dan hotel dibuat. Selesaikan pembayaran dan tunggu verifikasi pengelola.');
    }

    public function index(Request $r)
    {
        if(DB::table('platform_admins')->where('user_id',$r->user()->id)->exists())return redirect('/saas');
        $c=new HotelContext($r,false);$a=$c->table('saas_accounts')->first();$subscriber=$a&&$a->owner_user_id===$r->user()->id;
        return response()->view('saas.subscription',['hotel'=>$c->hotel,'account'=>$a,'subscriber'=>$subscriber,'access'=>SubscriptionAccess::state($c->hotel),'plan'=>$a?DB::table('subscription_plans')->find($a->plan_id):null,'invoices'=>$subscriber?$c->table('subscription_invoices')->orderByDesc('id')->limit(50)->get():collect(),'settings'=>DB::table('saas_settings')->find(1)])->header('Cache-Control','no-store');
    }

    private function subscriber(Request $r): array
    {
        $c=new HotelContext($r,false);$a=$c->table('saas_accounts')->first();abort_unless($a&&$a->owner_user_id===$r->user()->id,403,'Pengelolaan pembayaran langganan hanya untuk pemilik langganan hotel ini.');return[$c,$a];
    }

    public function renew(Request $r,SubscriptionService $service)
    {
        [$c,$a]=$this->subscriber($r);$d=$r->validate(['months'=>['required','integer',Rule::in([1,12])]]);
        $service->issue($c->hotel->id,$a->plan_id,(int)$d['months']);return back()->with('status','Tagihan perpanjangan tersedia. Tidak ada pembayaran otomatis yang diproses.');
    }

    public function report(Request $r,int $id)
    {
        [$c,$a]=$this->subscriber($r);$d=$r->validate(['reference'=>'required|string|min:3|max:200']);
        DB::transaction(function()use($c,$a,$id,$d){DB::table('saas_accounts')->where('id',$a->id)->lockForUpdate()->first();$i=$c->table('subscription_invoices')->where('id',$id)->lockForUpdate()->first();abort_unless($i,404);if($i->status!=='unpaid')throw ValidationException::withMessages(['invoice'=>'Tagihan sudah ditutup.']);if($i->reported_at){if($i->payment_reference!==$d['reference'])throw ValidationException::withMessages(['reference'=>'Laporan pembayaran sudah dikirim. Hubungi pengelola untuk koreksi.']);return;}DB::table('subscription_invoices')->where('id',$id)->update(['payment_reference'=>$d['reference'],'reported_at'=>now()->utc(),'updated_at'=>now()]);PlatformContext::audit('Pemilik melaporkan pembayaran langganan',$c->hotel->id,['invoice_id'=>$id]);});
        return back()->with('status','Laporan diterima. Pengelola akan memverifikasi pembayaran; masa aktif belum berubah.');
    }

    public function print(Request $r,int $id)
    {
        if($r->is('saas/*')){(new PlatformContext($r))->authorize('invoices.manage');$invoice=DB::table('subscription_invoices')->find($id);abort_unless($invoice,404);$hotel=DB::table('hotels')->find($invoice->hotel_id);}
        else{[$c]=$this->subscriber($r);$invoice=$c->table('subscription_invoices')->find($id);abort_unless($invoice,404);$hotel=$c->hotel;}
        return response()->view('saas.invoice',['invoice'=>$invoice,'hotel'=>$hotel,'settings'=>DB::table('saas_settings')->find(1)])->header('Cache-Control','no-store');
    }
}
