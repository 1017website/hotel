<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Seeder data contoh hanya boleh dijalankan di local/testing.');
        }
        if (DB::table('hotels')->exists()) { return; }
        DB::transaction(function () {
            $owner = User::create(['name' => 'Owner Contoh', 'email' => 'owner@example.test', 'password' => 'DemoHotel2026!']);
            $cleaner = User::create(['name' => 'Housekeeping Contoh', 'email' => 'housekeeping@example.test', 'password' => 'DemoHotel2026!']);
            $today = now('Asia/Jakarta')->startOfDay();
            foreach (['Hotel Contoh Utama', 'Hotel Contoh Cabang'] as $index => $name) {
                $hotel = DB::table('hotels')->insertGetId(['name' => $name, 'address' => 'Alamat contoh, ganti di pengaturan hotel', 'is_demo' => true, 'created_at' => now(), 'updated_at' => now()]);
                $role = DB::table('roles')->insertGetId(['hotel_id' => $hotel, 'name' => 'Owner', 'is_owner' => true, 'permissions' => '[]', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('hotel_user')->insert(['hotel_id' => $hotel, 'user_id' => $owner->id, 'role_id' => $role]);
                $presets = [
                    'Resepsionis' => ['rooms.view', 'reservations.view', 'reservations.manage', 'finance.view', 'payments.manage', 'food.view', 'food.manage'],
                    'Housekeeping' => ['rooms.view', 'housekeeping.manage', 'maintenance.view'],
                    'Dapur' => ['food.view', 'food.manage'],
                    'HR' => ['employees.view', 'employees.manage', 'shifts.manage', 'attendance.manage'],
                    'Teknisi' => ['rooms.view', 'maintenance.view', 'maintenance.manage'],
                ];
                foreach ($presets as $roleName => $permissions) {
                    $preset = DB::table('roles')->insertGetId(['hotel_id' => $hotel, 'name' => $roleName, 'permissions' => json_encode($permissions), 'created_at' => now(), 'updated_at' => now()]);
                    if ($roleName === 'Housekeeping' && $index === 0) { DB::table('hotel_user')->insert(['hotel_id' => $hotel, 'user_id' => $cleaner->id, 'role_id' => $preset]); }
                }
                $roomIds = [];
                for ($n = 0; $n < ($index === 0 ? 24 : 4); $n++) {
                    $floor = intdiv($n, 8) + 1;
                    $type = $n % 3 === 0 ? 'Deluxe' : ($n % 3 === 1 ? 'Superior' : 'Suite');
                    $rate = ['Deluxe' => 650000, 'Superior' => 450000, 'Suite' => 950000][$type];
                    $housekeeping = match ($n) { 4, 12 => 'dirty', 5, 13 => 'cleaning', 6 => 'inspection', default => 'clean' };
                    $roomIds[$n] = DB::table('rooms')->insertGetId(['hotel_id' => $hotel, 'number' => (string) ($floor * 100 + $n % 8 + 1), 'floor' => $floor, 'type' => $type, 'capacity' => $type === 'Suite' ? 4 : 2, 'rate' => $rate, 'facilities' => json_encode(['AC', 'Wi-Fi', 'Shower', $type === 'Suite' ? 'Ruang keluarga' : 'Meja kerja']), 'housekeeping' => $housekeeping, 'assigned_to' => in_array($n, [5, 13]) ? 'Petugas Contoh A' : null, 'out_of_service' => $n === 7, 'created_at' => now(), 'updated_at' => now()]);
                    if ($index === 0 && in_array($n, [0, 1, 2, 3, 8, 9, 10, 14, 17])) {
                        $occupied = in_array($n, [0, 1, 8, 9, 17]);
                        $arrival = $occupied ? $today->copy()->subDay() : $today->copy()->addDays($n === 14 ? 2 : 0);
                        $booking = DB::table('reservations')->insertGetId(['hotel_id' => $hotel, 'room_id' => $roomIds[$n], 'code' => 'CONTOH-'.($n + 1), 'guest' => 'Tamu Contoh '.($n + 1), 'arrival' => $arrival->toDateString(), 'departure' => $arrival->copy()->addDays(3)->toDateString(), 'adults' => 2, 'source' => $n % 2 ? 'Agoda' : 'Langsung', 'status' => $occupied ? 'checked_in' : 'confirmed', 'checked_in_at' => $occupied ? $arrival : null, 'total' => $rate * 3, 'notes' => 'Data contoh manual. Bukan reservasi dari koneksi OTA aktif.', 'created_at' => now(), 'updated_at' => now()]);
                        if ($occupied) { DB::table('payments')->insert(['hotel_id' => $hotel, 'reservation_id' => $booking, 'amount' => $rate, 'method' => 'Transfer', 'user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]); }
                    }
                }
                foreach (['Agoda', 'Traveloka', 'Booking.com', 'Expedia'] as $provider) { DB::table('channel_connections')->insert(['hotel_id' => $hotel, 'provider' => $provider, 'status' => 'not_connected', 'created_at' => now(), 'updated_at' => now()]); }
                foreach ([['Nasi goreng', 'Makanan', 45000], ['Sup ayam', 'Makanan', 40000], ['Kopi hitam', 'Minuman', 20000], ['Jus jeruk', 'Minuman', 25000]] as [$itemName, $category, $price]) { DB::table('food_items')->insert(['hotel_id' => $hotel, 'name' => $itemName, 'category' => $category, 'price' => $price, 'created_at' => now(), 'updated_at' => now()]); }
                foreach ([['Karyawan Contoh A', 'Housekeeping', 'Room attendant'], ['Karyawan Contoh B', 'Front Office', 'Resepsionis'], ['Karyawan Contoh C', 'Restoran', 'Koki']] as [$employeeName, $department, $position]) {
                    $employee = DB::table('employees')->insertGetId(['hotel_id' => $hotel, 'name' => $employeeName, 'department' => $department, 'position' => $position, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('shifts')->insert(['hotel_id' => $hotel, 'employee_id' => $employee, 'starts_at' => $today->copy()->setTime(7, 0), 'ends_at' => $today->copy()->setTime(15, 0), 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ([['Handuk', 'lembar', 32, 20], ['Sabun', 'buah', 8, 15], ['Seprai', 'set', 24, 12]] as [$stockName, $unit, $quantity, $minimum]) { DB::table('inventory')->insert(['hotel_id' => $hotel, 'name' => $stockName, 'unit' => $unit, 'quantity' => $quantity, 'minimum' => $minimum, 'created_at' => now(), 'updated_at' => now()]); }
                if ($index === 0) { DB::table('maintenance')->insert(['hotel_id' => $hotel, 'room_id' => $roomIds[7], 'description' => 'Contoh: pemeriksaan pendingin ruangan', 'assigned_to' => 'Teknisi Contoh', 'created_at' => now(), 'updated_at' => now()]); }
            }
        });
    }
}
