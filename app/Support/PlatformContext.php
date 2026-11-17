<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformContext
{
    public const PERMISSIONS = ['tenants.view', 'tenants.manage', 'subscriptions.manage', 'invoices.manage', 'plans.manage', 'maintenance.manage', 'settings.manage'];
    public readonly object $admin;

    public function __construct(Request $request)
    {
        $admin = DB::table('platform_admins')->where('user_id', $request->user()->id)->first();
        abort_unless($admin && $admin->active, 403, 'Akun ini tidak memiliki akses admin SaaS aktif.');
        $this->admin = $admin;
    }

    public function can(string $permission): bool
    {
        return (bool)$this->admin->is_super || in_array($permission, json_decode($this->admin->permissions, true), true);
    }

    public function authorize(string $permission): void
    {
        abort_unless($this->can($permission), 403, 'Izin admin SaaS tidak mencakup tindakan ini.');
    }

    public function super(): void
    {
        abort_unless($this->admin->is_super, 403, 'Pengelolaan sub-admin hanya untuk Super Admin SaaS.');
    }

    public static function audit(string $action, ?int $hotel = null, array $details = []): void
    {
        DB::table('platform_audit_logs')->insert(['user_id' => auth()->id(), 'action' => $action, 'hotel_id' => $hotel, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }
}
