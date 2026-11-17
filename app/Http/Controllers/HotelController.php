<?php

namespace App\Http\Controllers;

use App\Support\HotelContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HotelController extends Controller
{
    public function index(Request $request)
    {
        if (DB::table('platform_admins')->where('user_id', $request->user()->id)->where('active', true)->exists()) return redirect('/saas');
        $ctx = new HotelContext($request);
        return view('app', ['hotel' => $ctx->hotel, 'user' => $request->user()]);
    }

    public function data(Request $request)
    {
        $ctx = new HotelContext($request);
        $today = now($ctx->hotel->timezone)->toDateString();
        $data = [
            'hotel' => $ctx->hotel, 'user' => $request->user()->only('id', 'name', 'email'),
            'role' => $ctx->role->name,
            'permissions' => array_values(array_filter(HotelContext::PERMISSIONS, fn ($p) => $ctx->can($p))),
            'permission_options' => HotelContext::PERMISSIONS,
            'today' => $today, 'updated_at' => now($ctx->hotel->timezone)->format('H:i:s'),
            'subscription' => \App\Services\SubscriptionAccess::state($ctx->hotel),
            'hotels' => DB::table('hotels')->join('hotel_user', 'hotels.id', '=', 'hotel_user.hotel_id')
                ->where('hotel_user.user_id', $request->user()->id)->select('hotels.id', 'hotels.name')->get(),
        ];
        if ($ctx->can('rooms.view')) {
            $active = $ctx->table('reservations')->where(function ($q) use ($today) {
                $q->where('status', 'checked_in')->orWhere(fn ($q) => $q->where('status', 'confirmed')->where('arrival', '<=', $today)->where('departure', '>', $today));
            })->orderByRaw("CASE WHEN status = 'checked_in' THEN 0 ELSE 1 END")->get()->groupBy('room_id');
            $data['rooms'] = $ctx->table('rooms')->orderBy('floor')->orderBy('number')->get()->map(function ($room) use ($active, $ctx) {
                $booking = $active->get($room->id)?->first();
                $room->facilities = json_decode($room->facilities, true);
                $room->occupancy = $booking?->status === 'checked_in' ? 'occupied' : ($booking ? 'reserved' : 'vacant');
                $room->board_status = $room->out_of_service ? 'maintenance' : ($room->occupancy === 'occupied' ? 'occupied' : ($room->housekeeping !== 'clean' ? $room->housekeeping : ($booking ? 'reserved' : 'available')));
                $room->reservation = $ctx->can('reservations.view') ? $booking : null;
                if (! $ctx->can('finance.view') && ! $ctx->can('payments.manage') && $room->reservation) { unset($room->reservation->total, $room->reservation->room_total, $room->reservation->nightly_rate); }
                if (! $ctx->can('reservations.manage') && ! $ctx->can('finance.view')) { unset($room->rate); }
                return $room;
            });
        }
        $tables = [
            'reservations' => 'reservations.view', 'food_items' => 'food.view', 'food_orders' => 'food.view',
            'employees' => 'employees.view', 'shifts' => 'employees.view', 'attendances' => 'employees.view',
            'expenses' => 'expenses.view', 'inventory' => 'inventory.view', 'maintenance' => 'maintenance.view',
            'roles' => 'roles.manage', 'audit_logs' => 'audit.view',
        ];
        foreach ($tables as $table => $permission) {
            if (! $ctx->can($permission)) { continue; }
            $rows = $ctx->table($table)->orderByDesc('id')->limit(250)->get();
            if ($table === 'reservations') {
                $workToday = $ctx->table('reservations')
                    ->whereNotIn('id', $rows->pluck('id'))
                    ->where(fn ($q) => $q->where('status', 'checked_in')
                        ->orWhere(fn ($q) => $q->where('status', 'confirmed')->where('arrival', $today))
                        ->orWhere(fn ($q) => $q->where('status', 'checked_out')->where('departure', $today)))
                    ->get();
                $rows = $rows->merge($workToday)->sortByDesc('id')->values();
                $paid = $ctx->table('payments')->selectRaw('reservation_id, SUM(amount) as amount')->groupBy('reservation_id')->pluck('amount', 'reservation_id');
                $profiles=$ctx->table('guest_profiles')->whereIn('reservation_id',$rows->pluck('id'))->pluck('identity_last4','reservation_id');
                $refunds=$ctx->table('payment_refunds')->selectRaw('reservation_id, SUM(amount) as amount')->groupBy('reservation_id')->pluck('amount','reservation_id');
                $rows->each(function ($r) use ($paid, $ctx, $profiles, $refunds) {
                    $last4=$profiles[$r->id]??null;$r->has_identity=$last4!==null;$r->identity_masked=$last4!==null?'•••• '.$last4:null;
                    if ($ctx->can('finance.view') || $ctx->can('payments.manage')) { $r->paid = (int) ($paid[$r->id] ?? 0)-(int)($refunds[$r->id]??0); }
                    else { unset($r->total, $r->room_total, $r->nightly_rate); }
                });
            }
            if ($table === 'roles') { $rows->each(fn ($r) => $r->permissions = json_decode($r->permissions, true)); }
            if ($table === 'food_orders') { $rows->each(fn ($r) => $r->items = json_decode($r->items, true)); }
            $data[$table] = $rows;
            $data['counts'][$table] = $ctx->table($table)->count();
        }
        if ($ctx->can('reservations.view')) {
            $data['calendar_reservations'] = $ctx->table('reservations')->whereIn('status', ['confirmed', 'checked_in'])
                ->select('id', 'room_id', 'guest', 'arrival', 'departure', 'status')->get();
        }
        if ($ctx->can('food.view') && ! $ctx->can('reservations.view')) {
            $data['food_reservations'] = $ctx->table('reservations')->where('status', 'checked_in')
                ->select('id', 'room_id', 'code', 'status')->get();
        }
        if ($ctx->can('food.view')) {
            $data['restaurant_bookings'] = $ctx->table('reservations')->join('rooms','rooms.id','=','reservations.room_id')
                ->whereIn('reservations.status',['checked_in','checked_out'])->where('arrival','<',$today)->where('departure','>=',$today)
                ->select('reservations.id','reservations.code','rooms.number','reservations.adults','reservations.breakfast_guests','reservations.status')->get();
            $data['pos_rooms'] = $ctx->table('reservations')->join('rooms','rooms.id','=','reservations.room_id')->where('reservations.status','checked_in')->select('reservations.id','reservations.code','rooms.number')->get();
            $data['breakfast_visits'] = $ctx->table('breakfast_visits')->where('service_date',$today)->get();
        }
        if ($ctx->can('users.manage') && ! $ctx->can('roles.manage')) {
            $data['roles'] = $ctx->table('roles')->select('id', 'name', 'is_owner')->get();
        }
        if ($ctx->can('channels.view')) {
            $data['channels'] = $ctx->table('channel_connections')->select('id', 'provider', 'property_id', 'connection_mode', 'partner_name', 'status', 'last_synced_at', 'mapping')->get();
            $data['channels']->each(fn ($c) => $c->mapping = json_decode($c->mapping ?: '[]', true));
        }
        if ($ctx->can('users.manage')) {
            $data['members'] = $ctx->table('hotel_user')->join('users', 'users.id', '=', 'hotel_user.user_id')->select('users.id', 'users.name', 'users.email', 'hotel_user.role_id')->get();
        }
        if ($ctx->can('finance.view')) {
            $month = now($ctx->hotel->timezone)->startOfMonth();
            $nextMonth = $month->copy()->addMonth();
            $data['finance'] = [
                'income' => (int) $ctx->table('payments')->where('created_at', '>=', $month->copy()->utc())->where('created_at', '<', $nextMonth->copy()->utc())->sum('amount'),
                'expenses' => (int) $ctx->table('expenses')->where('status', 'approved')->where('date', '>=', $month->toDateString())->where('date', '<', $nextMonth->toDateString())->sum('amount'),
                'payments' => $ctx->table('payments')->orderByDesc('id')->limit(250)->get(),
                'restaurant_income' => (int) $ctx->table('food_orders')->where('payment_status','paid')->where('paid_at','>=',$month->copy()->utc())->where('paid_at','<',$nextMonth->copy()->utc())->sum('total'),
            ];
        }
        if (isset($data['finance'])) $data['finance']['income'] += $data['finance']['restaurant_income'] - (int)$ctx->table('payment_refunds')->where('created_at','>=',$month->copy()->utc())->where('created_at','<',$nextMonth->copy()->utc())->sum('amount');
        if($ctx->can('payments.manage')||$ctx->can('finance.view'))$data['deposits']=$ctx->table('deposit_entries')->orderByDesc('id')->get();
        if($ctx->can('payments.manage')||$ctx->can('finance.view'))$data['refunds']=$ctx->table('payment_refunds')->orderByDesc('id')->limit(250)->get();
        if($ctx->can('employees.view'))$data['leaves']=$ctx->table('leave_requests')->orderByDesc('id')->limit(250)->get();
        if($ctx->can('inventory.view'))$data['stock_movements']=$ctx->table('stock_movements')->orderByDesc('id')->limit(250)->get();
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function switchHotel(Request $request)
    {
        $data = $request->validate(['hotel_id' => 'required|integer']);
        abort_unless(DB::table('hotel_user')->where('user_id', $request->user()->id)->where('hotel_id', $data['hotel_id'])->exists(), 403);
        $request->session()->put('hotel_id', $data['hotel_id']);
        return response()->json(['message' => 'Hotel aktif diubah.']);
    }
}
