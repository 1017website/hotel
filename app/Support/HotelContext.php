<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HotelContext
{
    public const PERMISSIONS = [
        'rooms.view', 'rooms.manage', 'housekeeping.manage',
        'reservations.view', 'reservations.manage', 'reservations.cancel',
        'finance.view', 'finance.manage', 'payments.manage', 'food.view', 'food.manage',
        'employees.view', 'employees.manage', 'shifts.manage', 'attendance.manage', 'attendance.self', 'attendance.import',
        'expenses.view', 'expenses.manage', 'expenses.approve',
        'inventory.view', 'inventory.manage', 'maintenance.view', 'maintenance.manage',
        'channels.view', 'channels.manage', 'roles.manage', 'users.manage', 'audit.view', 'hotel.manage', 'guests.identity',
    ];

    public readonly object $hotel;
    public readonly object $role;

    public function __construct(Request $request, bool $checkSubscription = true)
    {
        abort_if(DB::table('platform_admins')->where('user_id', $request->user()->id)->exists(), 403, 'Akun admin SaaS tidak membuka operasional hotel.');
        $membership = DB::table('hotel_user')->where('user_id', $request->user()->id)
            ->when($request->session()->has('hotel_id'), fn ($q) => $q->where('hotel_id', $request->session()->get('hotel_id')))
            ->first();
        abort_unless($membership, 403, 'Anda belum memiliki akses ke hotel ini.');
        $this->hotel = DB::table('hotels')->find($membership->hotel_id);
        if (! $request->isMethod('GET') && $request->hasHeader('X-Hotel-ID')) {
            abort_unless((string) $request->header('X-Hotel-ID') === (string) $this->hotel->id, 409, 'Hotel aktif berubah di tab lain. Muat ulang sebelum menyimpan.');
        }
        $this->role = DB::table('roles')->where('hotel_id', $this->hotel->id)->find($membership->role_id);
        abort_unless($this->role, 403);
        $request->session()->put('hotel_id', $this->hotel->id);
        if ($checkSubscription) \App\Services\SubscriptionAccess::ensure($this->hotel);
    }

    public function can(string $permission): bool
    {
        return $this->role->is_owner || in_array($permission, self::expandPermissions(json_decode($this->role->permissions, true)), true);
    }

    public static function expandPermissions(array $permissions): array
    {
        $dependencies = [
            'rooms.manage' => ['rooms.view'], 'housekeeping.manage' => ['rooms.view'],
            'reservations.manage' => ['reservations.view', 'rooms.view'], 'reservations.cancel' => ['reservations.view'],
            'payments.manage' => ['reservations.view'], 'food.manage' => ['food.view'],
            'employees.manage' => ['employees.view'], 'shifts.manage' => ['employees.view'], 'attendance.manage' => ['employees.view'], 'attendance.import' => ['employees.view'],
            'expenses.manage' => ['expenses.view'], 'expenses.approve' => ['expenses.view'],
            'inventory.manage' => ['inventory.view'], 'maintenance.manage' => ['maintenance.view', 'rooms.view'],
            'channels.manage' => ['channels.view'],
            'finance.manage' => ['finance.view'], 'guests.identity' => ['reservations.view', 'rooms.view'],
        ];
        foreach ($permissions as $permission) { $permissions = array_merge($permissions, $dependencies[$permission] ?? []); }
        return array_values(array_unique($permissions));
    }

    public function authorize(string $permission): void
    {
        abort_unless($this->can($permission), 403, 'Peran Anda tidak diizinkan melakukan tindakan ini.');
    }

    public function table(string $table)
    {
        return DB::table($table)->where($table.'.hotel_id', $this->hotel->id);
    }

    public function audit(string $action, string $entity, ?int $id = null, array $details = []): void
    {
        DB::table('audit_logs')->insert([
            'hotel_id' => $this->hotel->id, 'user_id' => auth()->id(), 'action' => $action,
            'entity' => $entity, 'entity_id' => $id, 'details' => json_encode($details), 'created_at' => now(),
        ]);
    }
}
