<?php

namespace App\Services;

use App\Support\HotelContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function create(HotelContext $ctx, array $data): int
    {
        $data['breakfast_guests'] = (int) ($data['breakfast_guests'] ?? 0);
        return DB::transaction(function () use ($ctx, $data) {
            // All booking writers must lock the room before checking overlapping dates.
            $room = $ctx->table('rooms')->where('id', $data['room_id'])->lockForUpdate()->first();
            abort_unless($room, 404);
            if ($room->out_of_service || $data['adults'] > $room->capacity) {
                throw ValidationException::withMessages(['room_id' => 'Kamar dalam perbaikan atau kapasitas tidak mencukupi.']);
            }
            $overlap = $ctx->table('reservations')->where('room_id', $room->id)
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->where('arrival', '<', $data['departure'])->where('departure', '>', $data['arrival'])->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['room_id' => 'Kamar sudah dipesan pada rentang tanggal tersebut. Pilih kamar atau tanggal lain.']);
            }
            $nights = (int) Carbon::parse($data['arrival'])->diffInDays(Carbon::parse($data['departure']));
            $id = DB::table('reservations')->insertGetId([
                ...$data, 'hotel_id' => $ctx->hotel->id, 'code' => 'RSV-'.Str::upper(Str::random(8)),
                'status' => 'confirmed', 'total' => $nights * $room->rate, 'room_total'=>$nights*$room->rate,'nightly_rate'=>$room->rate, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $ctx->audit('Reservasi dibuat', 'reservations', $id);
            return $id;
        }, 3);
    }

    public function transition(HotelContext $ctx, int $id, string $action): void
    {
        $existing = $ctx->table('reservations')->find($id);
        abort_unless($existing, 404);
        DB::transaction(function () use ($ctx, $id, $action, $existing) {
            $room = $ctx->table('rooms')->where('id', $existing->room_id)->lockForUpdate()->first();
            $reservation = $ctx->table('reservations')->where('id', $id)->lockForUpdate()->first();
            if ($reservation->room_id !== $existing->room_id) {
                throw ValidationException::withMessages(['room_id' => 'Kamar telah berubah oleh petugas lain. Muat ulang sebelum melanjutkan.']);
            }
            $changes = ['updated_at' => now()];
            if ($action === 'checkin') {
                if (!$ctx->table('guest_profiles')->where('reservation_id',$id)->exists()) throw ValidationException::withMessages(['guest'=>'Lengkapi identitas tamu sebelum check-in.']);
                $today = now($ctx->hotel->timezone)->toDateString();
                $occupied = $ctx->table('reservations')->where('room_id', $room->id)->where('status', 'checked_in')->exists();
                if ($reservation->status !== 'confirmed' || $room->housekeeping !== 'clean' || $room->out_of_service || $occupied || $reservation->arrival > $today || $reservation->departure <= $today) {
                    throw ValidationException::withMessages(['status' => 'Check-in membutuhkan reservasi aktif untuk hari ini dan kamar bersih yang tidak ditempati atau diperbaiki.']);
                }
                $changes += ['status' => 'checked_in', 'checked_in_at' => now()];
            } elseif ($action === 'checkout') {
                $held=$ctx->table('deposit_entries')->where('reservation_id',$id)->selectRaw("COALESCE(SUM(CASE WHEN type='receive' THEN amount ELSE -amount END),0) as balance")->value('balance');
                if($held>0)throw ValidationException::withMessages(['deposit'=>'Selesaikan deposit: gunakan untuk tagihan atau catat pengembalian sebelum check-out.']);
                if ($reservation->status !== 'checked_in') {
                    throw ValidationException::withMessages(['status' => 'Hanya tamu yang sedang menginap dapat check-out.']);
                }
                if ($ctx->table('payments')->where('reservation_id', $id)->sum('amount')-$ctx->table('payment_refunds')->where('reservation_id',$id)->sum('amount') < $reservation->total) {
                    throw ValidationException::withMessages(['status' => 'Lunasi tagihan sebelum check-out.']);
                }
                $changes += ['status' => 'checked_out', 'checked_out_at' => now()];
                $ctx->table('rooms')->where('id', $room->id)->update(['housekeeping' => 'dirty', 'updated_at' => now()]);
            } else {
                $held=$ctx->table('deposit_entries')->where('reservation_id',$id)->selectRaw("COALESCE(SUM(CASE WHEN type='receive' THEN amount ELSE -amount END),0) as balance")->value('balance');
                if($held>0)throw ValidationException::withMessages(['deposit'=>'Kembalikan deposit sebelum membatalkan reservasi.']);
                if ($reservation->status !== 'confirmed' || $ctx->table('payments')->where('reservation_id', $id)->sum('amount')-$ctx->table('payment_refunds')->where('reservation_id',$id)->sum('amount')>0) {
                    throw ValidationException::withMessages(['status' => 'Pembatalan hanya untuk reservasi belum check-in tanpa pembayaran. Refund memerlukan alur terpisah.']);
                }
                $changes['status'] = 'cancelled';
            }
            $ctx->table('reservations')->where('id', $id)->update($changes);
            $ctx->audit($action, 'reservations', $id);
        }, 3);
    }
}
