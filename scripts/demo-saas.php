<?php

require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!$app->environment('local','testing'))throw new RuntimeException('Demo SaaS hanya untuk lokal/testing.');
use Illuminate\Support\Facades\DB;
use App\Models\User;

DB::transaction(function(){
    foreach([['saas-admin@example.test','Super Admin Contoh',true,[]],['saas-ops@example.test','Sub-admin Contoh',false,['tenants.view','subscriptions.manage','invoices.manage','maintenance.manage']]]as[$email,$name,$super,$permissions]){
        $user=User::firstOrCreate(['email'=>$email],['name'=>$name,'password'=>'DemoSaas2026!']);
        if(DB::table('hotel_user')->where('user_id',$user->id)->exists())throw new RuntimeException('Email demo platform sudah dipakai akun hotel; tidak diambil alih.');
        DB::table('platform_admins')->insertOrIgnore(['user_id'=>$user->id,'is_super'=>$super,'active'=>true,'permissions'=>json_encode($permissions),'created_at'=>now(),'updated_at'=>now()]);
    }
    DB::table('subscription_plans')->insertOrIgnore(['slug'=>'contoh-lokal','name'=>'Paket Contoh (lokal)','description'=>'Harga dan batas contoh untuk pengujian, bukan penawaran produksi.','monthly_price'=>120000,'yearly_price'=>1200000,'room_limit'=>30,'user_limit'=>10,'published'=>true,'created_at'=>now(),'updated_at'=>now()]);
    if(!User::where('email','subscriber@example.test')->exists()){
        $root=User::where('email','saas-admin@example.test')->first();auth()->login($root);
        $plan=DB::table('subscription_plans')->where('slug','contoh-lokal')->first();
        (new App\Services\SubscriptionService)->register(['hotel_name'=>'Hotel SaaS Contoh','name'=>'Pemilik SaaS Contoh','email'=>'subscriber@example.test','password'=>'DemoSaas2026!','plan_id'=>$plan->id,'months'=>1],true);
        DB::table('saas_settings')->where('id',1)->update(['signup_enabled'=>true,'payment_instructions'=>'DATA CONTOH LOKAL. Verifikasi hanya untuk mencoba alur aktivasi; tidak ada pembayaran nyata yang diminta.','updated_at'=>now()]);
    }
});
echo "Demo SaaS siap: saas-admin@example.test, saas-ops@example.test, subscriber@example.test. Password lokal: DemoSaas2026!\n";
