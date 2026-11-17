<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
Artisan::command('saas:setup {email? : Email Super Admin pertama}', function () {
    $seedStatus = $this->call('db:seed', ['--class' => \Database\Seeders\SaasPlanSeeder::class, '--force' => true]);
    if ($seedStatus !== 0) {
        return $seedStatus;
    }
    $this->info('Paket awal TataInap tersedia di panel SaaS.');

    if ($this->argument('email')) {
        return $this->call('saas:admin', ['email' => $this->argument('email')]);
    }

    if (! DB::table('platform_admins')->where('is_super', true)->where('active', true)->exists()) {
        $this->line('Buat Super Admin melalui php artisan saas:admin EMAIL.');
    }

    return 0;
})->purpose('Menyiapkan paket awal TataInap tanpa data contoh');

Artisan::command('saas:admin {email : Email baru khusus Super Admin SaaS}',function(){
    $email=strtolower(trim($this->argument('email')));$name=$this->ask('Nama Super Admin SaaS');$password=$this->secret('Kata sandi Super Admin (minimal 12 karakter)');
    $validation=Validator::make(compact('email','name','password'),['email'=>'required|email|max:200|unique:users,email','name'=>'required|string|max:120','password'=>['required','string','min:12',new \App\Rules\HotelPassword]]);
    if($validation->fails()){$this->error($validation->errors()->first());return 1;}
    DB::transaction(function()use($email,$name,$password){$user=User::create(compact('email','name','password'));DB::table('platform_admins')->insert(['user_id'=>$user->id,'is_super'=>true,'active'=>true,'permissions'=>'[]','created_at'=>now(),'updated_at'=>now()]);});
    $this->info('Super Admin SaaS dibuat. Masuk melalui /saas/login. Akun ini terpisah dari akun hotel.');return 0;
})->purpose('Bootstrap Super Admin SaaS melalui administrator server');

Artisan::command('saas:adopt {hotel : ID hotel lama} {email : Email Owner hotel} {plan? : ID paket}',function(){
    $hotel=(int)$this->argument('hotel');$user=User::where('email',strtolower($this->argument('email')))->first();
    if(!$user||!DB::table('hotel_user')->join('roles','roles.id','=','hotel_user.role_id')->where('hotel_user.hotel_id',$hotel)->where('hotel_user.user_id',$user->id)->where('roles.is_owner',true)->exists()){$this->error('Owner pada hotel tersebut tidak ditemukan.');return 1;}
    if(DB::table('saas_accounts')->where('hotel_id',$hotel)->exists()){$this->error('Hotel sudah mempunyai akun langganan.');return 1;}
    try{DB::transaction(fn()=>(new \App\Services\SubscriptionService)->attach($hotel,$user->id,$this->argument('plan')?(int)$this->argument('plan'):null));}catch(\Illuminate\Validation\ValidationException $e){$this->error($e->getMessage());return 1;}
    $this->info('Hotel masuk pengelolaan SaaS dengan status menunggu aktivasi. Data operasional dipertahankan.');return 0;
})->purpose('Memasukkan hotel lama ke langganan tanpa mengubah data hotel');
Artisan::command('ledger:sync {hotel?}',function(){ $ids=$this->argument('hotel')?[(int)$this->argument('hotel')]:DB::table('hotels')->pluck('id');foreach($ids as$id){\App\Services\LedgerService::rebuild($id);$this->info('Jurnal hotel '.$id.' diselaraskan.');} })->purpose('Memasukkan transaksi lama ke jurnal tanpa duplikasi');

Artisan::command('hotel:create {name : Nama hotel} {email : Email pemilik baru}', function () {
    $data = ['name' => $this->argument('name'), 'email' => $this->argument('email')];
    $validator = Validator::make($data, ['name' => 'required|string|max:120', 'email' => 'required|email|max:200|unique:users,email']);
    if ($validator->fails()) { $this->error($validator->errors()->first()); return 1; }
    $ownerName = $this->ask('Nama pemilik');
    $password = $this->secret('Kata sandi pemilik (minimal 12 karakter)');
    if (! $ownerName || strlen($ownerName) > 120 || strlen($password ?? '') < 12) { $this->error('Nama wajib diisi dan kata sandi minimal 12 karakter.'); return 1; }
    $passwordValidation = Validator::make(['password' => $password], ['password' => ['required','string','min:12',new \App\Rules\HotelPassword]]);
    if ($passwordValidation->fails()) { $this->error($passwordValidation->errors()->first()); return 1; }
    DB::transaction(function () use ($data, $ownerName, $password) {
        $hotel = DB::table('hotels')->insertGetId(['name' => $data['name'], 'is_demo' => false, 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('roles')->insertGetId(['hotel_id' => $hotel, 'name' => 'Owner', 'is_owner' => true, 'permissions' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::create(['name' => $ownerName, 'email' => $data['email'], 'password' => $password]);
        DB::table('hotel_user')->insert(['hotel_id' => $hotel, 'role_id' => $role, 'user_id' => $user->id]);
        (new \App\Services\SubscriptionService)->attach($hotel,$user->id);
        foreach (['Agoda', 'Traveloka', 'Booking.com', 'Expedia'] as $provider) {
            DB::table('channel_connections')->insert(['hotel_id' => $hotel, 'provider' => $provider, 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    $this->info('Hotel dan akun Owner berhasil dibuat tanpa data contoh.');
    return 0;
})->purpose('Membuat hotel baru dan akun Owner melalui administrator server');
