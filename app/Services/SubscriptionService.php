<?php

namespace App\Services;

use App\Models\User;
use App\Support\PlatformContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function register(array $data, bool $demo = false): object
    {
        return DB::transaction(function () use ($data, $demo) {
            $plan = DB::table('subscription_plans')->where('id', $data['plan_id'])->lockForUpdate()->first();
            if (!$plan || !$plan->published) $this->invalid('Paket belum tersedia untuk pendaftaran.');
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $hotel = DB::table('hotels')->insertGetId(['name' => $data['hotel_name'], 'is_demo' => $demo, 'timezone' => 'Asia/Jakarta', 'created_at' => now(), 'updated_at' => now()]);
            $role = DB::table('roles')->insertGetId(['hotel_id' => $hotel, 'name' => 'Owner', 'is_owner' => true, 'permissions' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('hotel_user')->insert(['hotel_id' => $hotel, 'user_id' => $user->id, 'role_id' => $role]);
            foreach (['Agoda', 'Traveloka', 'Booking.com', 'Expedia'] as $provider) DB::table('channel_connections')->insert(['hotel_id' => $hotel, 'provider' => $provider, 'created_at' => now(), 'updated_at' => now()]);
            $this->attach($hotel, $user->id, $plan->id);
            $this->issue($hotel, $plan->id, $data['months']);
            PlatformContext::audit('Hotel SaaS didaftarkan', $hotel);
            return (object)['hotel_id' => $hotel, 'user' => $user];
        }, 3);
    }

    public function attach(int $hotel, int $owner, ?int $planId = null): void
    {
        $plans = DB::table('subscription_plans');
        $plan = $planId
            ? $plans->find($planId)
            : (clone $plans)->where('published', true)->orderBy('id')->first() ?? $plans->orderBy('id')->first();
        if (!$plan) $this->invalid('Paket langganan tidak ditemukan.');
        if (DB::table('saas_accounts')->where('owner_user_id', $owner)->exists()) $this->invalid('Satu akun pemilik hanya boleh berlangganan untuk satu hotel.');
        if (DB::table('platform_admins')->where('user_id', $owner)->exists()) $this->invalid('Akun admin SaaS harus terpisah dari akun hotel.');
        DB::table('saas_accounts')->insert(['hotel_id' => $hotel, 'owner_user_id' => $owner, 'plan_id' => $plan->id, 'room_limit' => $plan->room_limit, 'user_limit' => $plan->user_limit, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function issue(int $hotel, int $planId, int $months): object
    {
        if (!in_array($months, [1, 12], true)) $this->invalid('Periode harus bulanan atau tahunan.');
        return DB::transaction(function () use ($hotel, $planId, $months) {
            $account = DB::table('saas_accounts')->where('hotel_id', $hotel)->lockForUpdate()->first();
            abort_unless($account, 404);
            $plan = DB::table('subscription_plans')->find($planId);
            if (!$plan || !$plan->published) $this->invalid('Paket tidak tersedia.');
            $amount = (int)($months === 12 ? $plan->yearly_price : $plan->monthly_price);
            if ($amount < 1) $this->invalid('Harga paket belum dikonfigurasi.');
            $old = DB::table('subscription_invoices')->where('hotel_id', $hotel)->where('status', 'unpaid')->first();
            if ($old) {
                if ($old->plan_id !== $planId || $old->months !== $months) $this->invalid('Masih ada tagihan belum dibayar. Selesaikan atau minta admin membatalkannya sebelum membuat tagihan berbeda.');
                return $old;
            }
            $id = DB::table('subscription_invoices')->insertGetId(['hotel_id' => $hotel, 'saas_account_id' => $account->id, 'plan_id' => $planId, 'code' => 'SUB-'.Str::upper(Str::random(12)), 'plan_name' => $plan->name, 'months' => $months, 'amount' => $amount, 'room_limit' => $plan->room_limit, 'user_limit' => $plan->user_limit, 'status' => 'unpaid', 'due_at' => now()->addDays(7)->utc(), 'created_at' => now(), 'updated_at' => now()]);
            PlatformContext::audit('Tagihan langganan dibuat', $hotel, ['invoice_id' => $id]);
            return DB::table('subscription_invoices')->find($id);
        }, 3);
    }

    public function paid(int $invoiceId, string $reference, string $method): object
    {
        $initial = DB::table('subscription_invoices')->find($invoiceId);
        abort_unless($initial, 404);
        return DB::transaction(function () use ($initial, $invoiceId, $reference, $method) {
            $account = DB::table('saas_accounts')->where('id', $initial->saas_account_id)->lockForUpdate()->first();
            $invoice = DB::table('subscription_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice->status === 'paid') return $invoice;
            if ($invoice->status !== 'unpaid') $this->invalid('Tagihan telah dibatalkan. Pembayaran perlu ditangani terpisah.');
            $start = $account->ends_at && $account->ends_at > now()->utc()->format('Y-m-d H:i:s') ? Carbon::parse($account->ends_at, 'UTC') : now()->utc();
            $end = $start->copy()->addMonthsNoOverflow($invoice->months);
            DB::table('subscription_invoices')->where('id', $invoiceId)->update(['status' => 'paid', 'payment_reference' => $reference, 'payment_method' => $method, 'paid_at' => now()->utc(), 'verified_by' => auth()->id(), 'period_starts_at' => $start, 'period_ends_at' => $end, 'updated_at' => now()]);
            DB::table('saas_accounts')->where('id', $account->id)->update(['plan_id' => $invoice->plan_id, 'room_limit' => $invoice->room_limit, 'user_limit' => $invoice->user_limit, 'ends_at' => $end, 'status' => $account->status === 'suspended' ? 'suspended' : 'active', 'updated_at' => now()]);
            PlatformContext::audit('Pembayaran langganan diverifikasi', $invoice->hotel_id, ['invoice_id' => $invoiceId, 'amount' => $invoice->amount, 'ends_at' => $end->toDateTimeString()]);
            return DB::table('subscription_invoices')->find($invoiceId);
        }, 3);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['subscription' => $message]);
    }
}
