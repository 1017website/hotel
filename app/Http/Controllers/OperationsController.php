<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReservationService;
use App\Support\HotelContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    private function exists(HotelContext $ctx, string $table)
    {
        return Rule::exists($table, 'id')->where('hotel_id', $ctx->hotel->id);
    }

    public function store(Request $request, string $module)
    {
        $ctx = new HotelContext($request);
        if ($module === 'reservations') {
            $ctx->authorize('reservations.manage');
            $data = $request->validate([
                'room_id' => ['required', 'integer', $this->exists($ctx, 'rooms')],
                'guest' => 'required|string|max:120', 'phone' => 'nullable|string|max:30',
                'arrival' => 'required|date_format:Y-m-d|after_or_equal:'.$this->today($ctx),
                'departure' => 'required|date_format:Y-m-d|after:arrival', 'adults' => 'required|integer|min:1|max:20',
                'source' => ['required', Rule::in(['Langsung', 'Telepon', 'Website', 'Agoda', 'Traveloka', 'Booking.com', 'Lainnya'])],
                'notes' => 'nullable|string|max:1000',
                'breakfast_guests' => 'nullable|integer|min:0|lte:adults',
            ]);
            (new ReservationService)->create($ctx, $data);
            return $this->ok('Reservasi tersimpan.');
        }
        return DB::transaction(function () use ($request, $module, $ctx) {
            switch ($module) {
                case 'rooms':
                    $ctx->authorize('rooms.manage');
                    DB::table('hotels')->where('id',$ctx->hotel->id)->lockForUpdate()->first();
                    \App\Services\SubscriptionAccess::quota($ctx->hotel,'rooms');
                    $data = $request->validate([
                        'number' => ['required', 'string', 'max:12', Rule::unique('rooms')->where('hotel_id', $ctx->hotel->id)],
                        'type' => 'required|string|max:80', 'floor' => 'required|integer|min:0|max:200',
                        'capacity' => 'required|integer|min:1|max:20', 'rate' => 'required|integer|min:0|max:100000000', 'facilities' => 'nullable|string|max:1000',
                    ]);
                    $data['facilities'] = json_encode(array_values(array_filter(array_map('trim', explode(',', $data['facilities'] ?? '')))));
                    $id = $this->insert($ctx, $module, $data); break;
                case 'food_items':
                    $ctx->authorize('food.manage');
                    $data = $request->validate(['name' => 'required|string|max:120', 'category' => 'required|string|max:80', 'price' => 'required|integer|min:0|max:10000000','outlet'=>['nullable',Rule::in(['restaurant','spa','facility'])]]);
                    $id = $this->insert($ctx, $module, $data); break;
                case 'food_orders':
                    $ctx->authorize('food.manage');
                    $data = $request->validate([
                        'reservation_id' => ['required', 'integer', $this->exists($ctx, 'reservations')],
                        'food_item_id' => ['required', 'integer', $this->exists($ctx, 'food_items')],
                        'quantity' => 'required|integer|min:1|max:100', 'notes' => 'nullable|string|max:500',
                    ]);
                    $booking = $ctx->table('reservations')->where('id', $data['reservation_id'])->lockForUpdate()->first();
                    if ($booking->status !== 'checked_in') { $this->invalid('Pesanan kamar hanya untuk tamu yang sedang menginap.'); }
                    $item = $ctx->table('food_items')->where('id', $data['food_item_id'])->where('available', true)->first();
                    if (! $item) { $this->invalid('Menu tidak tersedia.'); }
                    $total = $item->price * $data['quantity'];
                    $id = $this->insert($ctx, $module, ['reservation_id' => $booking->id, 'items' => json_encode([['name' => $item->name, 'quantity' => $data['quantity'], 'price' => $item->price]]), 'total' => $total, 'notes' => $data['notes'] ?? null]);
                    $ctx->table('reservations')->where('id', $booking->id)->increment('total', $total, ['updated_at' => now()]); break;
                case 'employees':
                    $ctx->authorize('employees.manage');
                    $data = $request->validate(['name' => 'required|string|max:120', 'department' => 'required|string|max:80', 'position' => 'required|string|max:80', 'phone' => 'nullable|string|max:30']);
                    $id = $this->insert($ctx, $module, $data); break;
                case 'shifts':
                    $ctx->authorize('shifts.manage');
                    $data = $request->validate(['employee_id' => ['required', 'integer', $this->exists($ctx, 'employees')], 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'notes' => 'nullable|string|max:500']);
                    $employee = $ctx->table('employees')->where('id', $data['employee_id'])->lockForUpdate()->first();
                    if (! $employee->active) { $this->invalid('Karyawan nonaktif tidak dapat dijadwalkan.'); }
                    $data['starts_at'] = Carbon::parse($data['starts_at'])->format('Y-m-d H:i:s');
                    $data['ends_at'] = Carbon::parse($data['ends_at'])->format('Y-m-d H:i:s');
                    if ($ctx->table('shifts')->where('employee_id', $data['employee_id'])->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists()) { $this->invalid('Jadwal karyawan bertabrakan dengan shift lain.'); }
                    $id = $this->insert($ctx, $module, $data); break;
                case 'attendances':
                    $ctx->authorize('attendance.manage');
                    $request->validate(['action'=>['nullable',Rule::in(['clockin','clockout'])]]);
                    $data = $request->validate(['employee_id' => ['required', 'integer', $this->exists($ctx, 'employees')]]);
                    $id=(new \App\Services\AttendanceService)->clock($ctx,(int)$data['employee_id'],$request->input('action','clockin')??'clockin'); break;
                case 'expenses':
                    $ctx->authorize('expenses.manage');
                    $data = $request->validate(['description' => 'required|string|max:200', 'category' => 'required|string|max:80', 'amount' => 'required|integer|min:1|max:1000000000', 'date' => 'required|date_format:Y-m-d']);
                    $id = $this->insert($ctx, $module, [...$data, 'user_id' => $request->user()->id]); break;
                case 'inventory':
                    $ctx->authorize('inventory.manage');
                    $data = $request->validate(['name' => 'required|string|max:120', 'unit' => 'required|string|max:30', 'quantity' => 'required|integer|min:0|max:1000000', 'minimum' => 'required|integer|min:0|max:1000000']);
                    $id = $this->insert($ctx, $module, $data); break;
                case 'maintenance':
                    $ctx->authorize('maintenance.manage');
                    $data = $request->validate(['room_id' => ['required', 'integer', $this->exists($ctx, 'rooms')], 'description' => 'required|string|max:200', 'assigned_to' => 'nullable|string|max:120']);
                    $ctx->table('rooms')->where('id', $data['room_id'])->lockForUpdate()->first();
                    $id = $this->insert($ctx, $module, $data);
                    $ctx->table('rooms')->where('id', $data['room_id'])->update(['out_of_service' => true, 'updated_at' => now()]); break;
                case 'roles':
                    $ctx->authorize('roles.manage');
                    $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('roles')->where('hotel_id', $ctx->hotel->id)], 'permissions' => 'present|array', 'permissions.*' => [Rule::in(HotelContext::PERMISSIONS)]]);
                    $id = $this->insert($ctx, $module, ['name' => $data['name'], 'permissions' => json_encode(HotelContext::expandPermissions($data['permissions']))]); break;
                case 'members':
                    $ctx->authorize('users.manage');
                    DB::table('hotels')->where('id',$ctx->hotel->id)->lockForUpdate()->first();
                    \App\Services\SubscriptionAccess::quota($ctx->hotel,'users');
                    $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:200|unique:users,email', 'password' => ['required','string','min:12','max:100',new \App\Rules\HotelPassword], 'role_id' => ['required', 'integer', $this->exists($ctx, 'roles')]]);
                    $role = $ctx->table('roles')->find($data['role_id']);
                    if ($role->is_owner && ! $ctx->role->is_owner) { abort(403); }
                    $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
                    $id = DB::table('hotel_user')->insertGetId(['hotel_id' => $ctx->hotel->id, 'user_id' => $user->id, 'role_id' => $role->id]); break;
                default: abort(404);
            }
            $ctx->audit('Data ditambahkan', $module, $id);
            return $this->ok('Data tersimpan.');
        }, 3);
    }

    public function update(Request $request, string $module, int $id)
    {
        $ctx = new HotelContext($request);
        if ($module === 'reservations') {
            $data = $request->validate(['action' => ['required', Rule::in(['checkin', 'checkout', 'cancel'])]]);
            $ctx->authorize($data['action'] === 'cancel' ? 'reservations.cancel' : 'reservations.manage');
            (new ReservationService)->transition($ctx, $id, $data['action']);
            return $this->ok('Status reservasi diperbarui.');
        }
        return DB::transaction(function () use ($ctx, $request, $module, $id) {
            if ($module === 'payments') {
                $ctx->authorize('payments.manage');
                $data = $request->validate(['amount' => 'required|integer|min:1|max:1000000000', 'method' => ['required', Rule::in(['Tunai', 'Transfer', 'Kartu', 'OTA'])], 'request_key' => 'nullable|uuid']);
                $booking = $ctx->table('reservations')->where('id', $id)->lockForUpdate()->first();
                abort_unless($booking, 404);
                if (!empty($data['request_key']) && ($old = $ctx->table('payments')->where('request_key', $data['request_key'])->first())) {
                    if ($old->reservation_id !== $id) { $this->invalid('Referensi pembayaran sudah digunakan pada reservasi lain.'); }
                    return $this->ok('Pembayaran sudah tercatat.');
                }
                $paid = $ctx->table('payments')->where('reservation_id', $id)->sum('amount')-$ctx->table('payment_refunds')->where('reservation_id',$id)->sum('amount');
                if (! in_array($booking->status, ['confirmed', 'checked_in']) || $data['amount'] > $booking->total - $paid) { $this->invalid('Nominal melebihi sisa tagihan atau reservasi sudah ditutup.'); }
                $paymentId = $this->insert($ctx, 'payments', [...$data, 'reservation_id' => $id, 'user_id' => $request->user()->id]);
                $ctx->audit('Pembayaran diterima', 'payments', $paymentId, ['amount' => $data['amount']]);
                \App\Services\LedgerService::source('payments',$paymentId);
                return $this->ok('Pembayaran tercatat.');
            }
            $ctx->authorize(match ($module) {
                'room_details' => 'rooms.manage', 'employee_details' => 'employees.manage', 'menu_details' => 'food.manage',
                'rooms' => 'housekeeping.manage', 'food_orders' => 'food.manage', 'expenses' => 'expenses.approve',
                'inventory' => 'inventory.manage', 'maintenance' => 'maintenance.manage', 'roles' => 'roles.manage',
                'members' => 'users.manage', 'channels' => 'channels.manage', 'hotel' => 'hotel.manage', default => abort(404),
            });
            $table = match ($module) { 'room_details' => 'rooms', 'employee_details' => 'employees', 'menu_details' => 'food_items', 'channels' => 'channel_connections', 'members' => 'hotel_user', default => $module };
            if ($module === 'hotel') {
                abort_unless($id === $ctx->hotel->id, 404);
                $data = $request->validate(['name' => 'required|string|max:120', 'address' => 'nullable|string|max:250', 'timezone' => ['required', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])]]);
                DB::table('hotels')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            } else {
                $row = $module === 'members' ? $ctx->table($table)->where('user_id', $id)->lockForUpdate()->first() : $ctx->table($table)->where('id', $id)->lockForUpdate()->first();
                abort_unless($row, 404);
                switch ($module) {
                    case 'room_details':
                        $data = $request->validate([
                            'number' => ['required', 'string', 'max:12', Rule::unique('rooms')->where('hotel_id', $ctx->hotel->id)->ignore($id)],
                            'type' => 'required|string|max:80', 'floor' => 'required|integer|min:0|max:200', 'capacity' => 'required|integer|min:1|max:20',
                            'rate' => 'required|integer|min:0|max:100000000', 'facilities' => 'nullable|string|max:1000',
                        ]);
                        if ($ctx->table('reservations')->where('room_id', $id)->whereIn('status', ['confirmed', 'checked_in'])->where('adults', '>', $data['capacity'])->exists()) {
                            $this->invalid('Kapasitas baru lebih kecil dari jumlah tamu pada reservasi aktif.');
                        }
                        $data['facilities'] = json_encode(array_values(array_filter(array_map('trim', explode(',', $data['facilities'] ?? ''))))); break;
                    case 'employee_details':
                        $data = $request->validate(['name' => 'required|string|max:120', 'department' => 'required|string|max:80', 'position' => 'required|string|max:80', 'phone' => 'nullable|string|max:30', 'active' => 'required|boolean']); break;
                    case 'menu_details':
                        $data = $request->validate(['name' => 'required|string|max:120', 'category' => 'required|string|max:80', 'price' => 'required|integer|min:0|max:10000000', 'available' => 'required|boolean']); break;
                    case 'rooms':
                        $data = $request->validate([
                            'housekeeping' => ['required', Rule::in(['dirty', 'cleaning', 'inspection', 'clean'])],
                            'assigned_to' => 'nullable|string|max:120',
                        ]);
                        if ($ctx->table('reservations')->where('room_id', $id)->where('status', 'checked_in')->exists()) {
                            $this->invalid('Kamar masih ditempati. Perbarui kebersihan setelah tamu check-out.');
                        }
                        if ($request->boolean('advance_housekeeping')) {
                            $next = ['dirty' => 'cleaning', 'cleaning' => 'inspection', 'inspection' => 'clean'];
                            if ($row->out_of_service || ($next[$row->housekeeping] ?? null) !== $data['housekeeping']) {
                                return response()->json(['message' => 'Status kamar berubah. Periksa antrean terbaru.'], 409);
                            }
                        }
                        break;
                    case 'food_orders':
                        $data = $request->validate(['status' => ['required', Rule::in(['preparing', 'delivering', 'served'])]]);
                        $next = ['new' => 'preparing', 'preparing' => 'delivering', 'delivering' => 'served'];
                        if (($next[$row->status] ?? null) !== $data['status']) { $this->invalid('Ikuti urutan: baru, diproses, diantar, selesai.'); } break;
                    case 'expenses':
                        if ($row->status !== 'pending') { $this->invalid('Pengeluaran sudah diproses.'); }
                        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]); $data['approved_by'] = $request->user()->id; break;
                    case 'inventory':
                        $data = $request->validate(['quantity' => 'required|integer|min:0|max:1000000']);
                        $ctx->audit('Penyesuaian stok', $module, $id, ['before' => $row->quantity, 'after' => $data['quantity']]); break;
                    case 'maintenance':
                        if ($row->status === 'resolved') { $this->invalid('Pekerjaan sudah selesai.'); }
                        $request->validate(['status' => ['required', Rule::in(['resolved'])]]);
                        $room = $ctx->table('rooms')->where('id', $row->room_id)->lockForUpdate()->first();
                        $others = $ctx->table('maintenance')->where('room_id', $room->id)->where('id', '!=', $id)->where('status', 'open')->exists();
                        $ctx->table('rooms')->where('id', $room->id)->update(['out_of_service' => $others, 'housekeeping' => 'dirty', 'updated_at' => now()]); $data = ['status' => 'resolved']; break;
                    case 'roles':
                        if ($row->is_owner) { $this->invalid('Peran Owner tidak dapat diubah agar akses pengelolaan tetap tersedia.'); }
                        $data = $request->validate(['permissions' => 'present|array', 'permissions.*' => [Rule::in(HotelContext::PERMISSIONS)]]); $data['permissions'] = json_encode(HotelContext::expandPermissions($data['permissions'])); break;
                    case 'members':
                        if ($ctx->table('roles')->find($row->role_id)->is_owner) { $this->invalid('Keanggotaan Owner tidak dapat diubah lewat formulir ini.'); }
                        $data = $request->validate(['role_id' => ['required', 'integer', $this->exists($ctx, 'roles')]]);
                        if ($ctx->table('roles')->find($data['role_id'])->is_owner && ! $ctx->role->is_owner) { abort(403); } break;
                    case 'channels':
                        $data = $request->validate(['property_id' => 'required|string|max:120', 'connection_mode' => ['required', Rule::in(['direct', 'channel_manager'])], 'partner_name' => 'nullable|string|max:120', 'credentials' => 'nullable|string|max:4000', 'mapping' => 'nullable|json|max:8000']);
                        if (! empty($data['credentials'])) { $data['credentials'] = encrypt($data['credentials']); } else { unset($data['credentials']); }
                        $data['status'] = 'pending_verification'; break;
                }
                $ctx->table($table)->where('id', $row->id)->update($module === 'members' ? $data : [...$data, 'updated_at' => now()]);
            }
            if($module==='expenses')\App\Services\LedgerService::source('expenses',$id);
            $ctx->audit('Data diperbarui', $module, $id);
            return $this->ok($module === 'channels' ? 'Konfigurasi tersimpan. Koneksi belum aktif; akses mitra dan konektor produksi masih diperlukan.' : 'Perubahan tersimpan.');
        }, 3);
    }

    private function insert(HotelContext $ctx, string $table, array $data): int
    {
        return DB::table($table)->insertGetId([...$data, 'hotel_id' => $ctx->hotel->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function today(HotelContext $ctx): string { return now($ctx->hotel->timezone)->toDateString(); }
    private function invalid(string $message): never { throw ValidationException::withMessages(['data' => $message]); }
    private function ok(string $message) { return response()->json(['message' => $message]); }
}
