<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local'))throw new RuntimeException('Fixture hanya untuk lokal.');
use Illuminate\Support\Facades\DB;
$owner=DB::table('users')->where('email','owner@example.test')->first();if(!$owner)throw new RuntimeException('Akun contoh tidak tersedia.');
$hotel=DB::table('hotels')->where('name','Hotel Contoh 20 Lantai')->where('is_demo',true)->first();
if(!$hotel)DB::transaction(function()use($owner){
    $id=DB::table('hotels')->insertGetId(['name'=>'Hotel Contoh 20 Lantai','is_demo'=>true,'address'=>'Gedung contoh untuk pengujian tampilan','timezone'=>'Asia/Jakarta','created_at'=>now(),'updated_at'=>now()]);
    $role=DB::table('roles')->insertGetId(['hotel_id'=>$id,'name'=>'Owner','is_owner'=>true,'permissions'=>'[]','created_at'=>now(),'updated_at'=>now()]);DB::table('hotel_user')->insert(['hotel_id'=>$id,'user_id'=>$owner->id,'role_id'=>$role]);
    for($floor=1;$floor<=20;$floor++)for($n=1;$n<=($floor===1?32:8);$n++)DB::table('rooms')->insert(['hotel_id'=>$id,'number'=>sprintf('%02d%02d',$floor,$n),'type'=>'Standard Contoh','floor'=>$floor,'capacity'=>2,'rate'=>500000,'facilities'=>json_encode(['AC','Wi-Fi']),'housekeeping'=>'clean','created_at'=>now(),'updated_at'=>now()]);
});
echo "Hotel Contoh 20 Lantai tersedia untuk akun Owner contoh.\n";
