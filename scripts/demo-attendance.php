<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local', 'testing')) throw new RuntimeException('Contoh absensi hanya untuk lokal/testing.');
$hotel = Illuminate\Support\Facades\DB::table('hotels')->where('name', 'Hotel Contoh 20 Lantai')->where('is_demo', true)->first();
if (!$hotel) throw new RuntimeException('Jalankan demo-building.php terlebih dahulu.');
$user = Illuminate\Support\Facades\DB::table('users')->where('email', 'owner@example.test')->first();
if (!$user || !Illuminate\Support\Facades\DB::table('hotel_user')->where('hotel_id', $hotel->id)->where('user_id', $user->id)->exists()) throw new RuntimeException('Owner contoh tidak ditemukan.');
Illuminate\Support\Facades\DB::table('employees')->updateOrInsert(['hotel_id' => $hotel->id, 'name' => 'Karyawan Uji Mandiri (contoh)'], ['department' => 'Front Office', 'position' => 'Petugas contoh', 'active' => true, 'user_id' => $user->id, 'attendance_code' => 'DEMO-001', 'updated_at' => now()]);
echo "Akun Owner contoh dihubungkan ke karyawan contoh hanya di Hotel Contoh 20 Lantai.\n";
