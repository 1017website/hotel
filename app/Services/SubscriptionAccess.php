<?php

namespace App\Services;

use App\Exceptions\SubscriptionUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionAccess
{
    public static function maintenance(object $record): bool
    {
        return (bool)$record->maintenance && (!$record->maintenance_until || $record->maintenance_until > now()->utc()->format('Y-m-d H:i:s'));
    }

    public static function state(object $hotel, ?object $account = null, ?object $settings = null): array
    {
        $settings ??= DB::table('saas_settings')->find(1);
        if ($settings && self::maintenance($settings)) return ['state' => 'maintenance', 'message' => $settings->maintenance_message ?: 'Layanan sedang dalam pemeliharaan. Silakan coba kembali setelah selesai.'];
        $account ??= DB::table('saas_accounts')->where('hotel_id', $hotel->id)->first();
        if (!$account) return $hotel->is_demo ? ['state' => 'demo', 'message' => 'Hotel contoh tanpa tagihan SaaS.'] : ['state' => 'setup_required', 'message' => 'Langganan hotel belum dikonfigurasi. Hubungi pengelola layanan.'];
        if ($account->status === 'suspended') return ['state' => 'suspended', 'message' => $account->suspend_reason ?: 'Akses hotel sedang disuspend. Hubungi pengelola layanan.'];
        if (self::maintenance($account)) return ['state' => 'maintenance', 'message' => $account->maintenance_message ?: 'Hotel sedang dalam pemeliharaan sementara.'];
        if ($account->status === 'pending') return ['state' => 'pending', 'message' => 'Langganan menunggu aktivasi. Periksa tagihan dan instruksi pembayaran.'];
        if (!$account->ends_at || $account->ends_at <= now()->utc()->format('Y-m-d H:i:s')) return ['state' => 'expired', 'message' => 'Masa langganan hotel telah berakhir. Perpanjang untuk melanjutkan operasional.'];
        return ['state' => 'active', 'message' => 'Langganan aktif.'];
    }

    public static function ensure(object $hotel, bool $publicBooking = false): void
    {
        $state = self::state($hotel);
        if (!in_array($state['state'], ['active', 'demo'])) throw new SubscriptionUnavailable($state['state'], $publicBooking ? 'Pemesanan hotel sedang tidak tersedia. Hubungi hotel untuk bantuan.' : $state['message'], $publicBooking);
    }

    public static function quota(object $hotel, string $type): void
    {
        $account = DB::table('saas_accounts')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
        if (!$account) return;
        $table = $type === 'rooms' ? 'rooms' : 'hotel_user';
        $limit = $type === 'rooms' ? $account->room_limit : $account->user_limit;
        if (DB::table($table)->where('hotel_id', $hotel->id)->count() >= $limit) throw ValidationException::withMessages(['quota' => 'Batas '.($type === 'rooms' ? 'kamar' : 'akun pengguna').' pada paket langganan sudah tercapai. Hubungi pengelola untuk penyesuaian paket.']);
    }
}
