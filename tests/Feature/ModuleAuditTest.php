<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModuleAuditTest extends TestCase
{
    use RefreshDatabase;

    private int $hotel;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(3, 0));
        $this->seed();
        $this->hotel = DB::table('hotels')->min('id');
        $this->owner = User::where('email', 'owner@example.test')->first();
        $this->actingAs($this->owner)->withSession(['hotel_id' => $this->hotel]);
    }

    private function booking(): int
    {
        $room = DB::table('rooms')->where('hotel_id', $this->hotel)->where('number', '204')->value('id');
        $this->postJson('/api/reservations', ['room_id' => $room, 'guest' => 'Audit Contoh', 'arrival' => '2026-11-16', 'departure' => '2026-11-18', 'adults' => 2, 'source' => 'Langsung'])->assertOk();
        return DB::table('reservations')->max('id');
    }

    public function test_nominal_snapshots_are_hidden_in_every_reservation_payload(): void
    {
        $id = $this->booking();
        $role = DB::table('roles')->insertGetId(['hotel_id' => $this->hotel, 'name' => 'Audit pembaca', 'permissions' => json_encode(['rooms.view', 'reservations.view'])]);
        DB::table('hotel_user')->where('user_id', $this->owner->id)->where('hotel_id', $this->hotel)->update(['role_id' => $role]);
        $data = $this->getJson('/api/data')->assertOk()->json();
        $booking = collect($data['reservations'])->firstWhere('id', $id);
        foreach (['total', 'room_total', 'nightly_rate', 'paid'] as $field) {
            $this->assertArrayNotHasKey($field, $booking);
            foreach ($data['rooms'] as $room) if ($room['reservation']) $this->assertArrayNotHasKey($field, $room['reservation']);
            $this->getJson('/api/reservation-detail/'.$id)->assertJsonMissingPath('reservation.'.$field);
        }
    }

    public function test_payment_and_stock_retries_do_not_duplicate_value(): void
    {
        $id = $this->booking();
        $key = (string) Str::uuid();
        $payment = ['amount' => 100000, 'method' => 'Tunai', 'request_key' => $key];
        $this->putJson('/api/payments/'.$id, $payment)->assertOk();
        $this->putJson('/api/payments/'.$id, $payment)->assertOk();
        $this->assertSame(1, DB::table('payments')->where('request_key', $key)->count());
        $item = DB::table('inventory')->where('hotel_id', $this->hotel)->first();
        $move = ['type' => 'consume', 'quantity' => 1, 'notes' => 'Audit contoh', 'request_key' => (string) Str::uuid()];
        $this->postJson('/api/stock/'.$item->id, $move)->assertOk();
        $this->postJson('/api/stock/'.$item->id, $move)->assertOk();
        $this->assertEquals($item->quantity - 1, DB::table('inventory')->where('id', $item->id)->value('quantity'));
        $this->assertSame(1, DB::table('stock_movements')->where('request_key', $move['request_key'])->count());
    }

    public function test_stale_browser_hotel_cannot_write_into_another_property(): void
    {
        $other = DB::table('hotels')->where('id', '!=', $this->hotel)->value('id');
        $this->withSession(['hotel_id' => $other])->withHeader('X-Hotel-ID', (string) $this->hotel)
            ->postJson('/api/expenses', ['description' => 'Salah hotel', 'category' => 'Audit', 'amount' => 1000, 'date' => '2026-11-16'])->assertConflict();
        $this->assertDatabaseMissing('expenses', ['description' => 'Salah hotel']);
    }

    public function test_housekeeping_cannot_call_management_endpoints_directly(): void
    {
        $id = $this->booking();
        $this->actingAs(User::where('email', 'housekeeping@example.test')->first());
        foreach (['/api/reservation-detail/'.$id, '/api/guests/'.$id, '/api/ledger?from=2026-11-16&to=2026-11-16', '/api/workforce?date=2026-11-16'] as $url) $this->getJson($url)->assertForbidden();
        foreach (['/api/ledger-rebuild', '/api/ledger-accounts', '/api/ledger-adjustment', '/api/refunds/'.$id, '/api/deposits/'.$id, '/api/leaves', '/api/attendance-correction', '/api/booking-settings', '/api/stock/1', '/api/food_orders', '/api/roles', '/api/members', '/api/expenses'] as $url) $this->postJson($url, [])->assertForbidden();
        $this->putJson('/api/stays/'.$id, [])->assertForbidden();
        $this->putJson('/api/stock/1/kind', [])->assertForbidden();
    }

    public function test_inactive_employee_open_clock_remains_visible_and_can_be_closed(): void
    {
        $employee = DB::table('employees')->where('hotel_id', $this->hotel)->value('id');
        DB::table('attendances')->where('employee_id', $employee)->delete();
        DB::table('attendances')->insert(['hotel_id' => $this->hotel, 'employee_id' => $employee, 'date' => '2026-11-14', 'clock_in' => '2026-11-14 22:00:00']);
        DB::table('employees')->where('id', $employee)->update(['active' => false]);
        $this->getJson('/api/workforce?date=2026-11-16')->assertOk()->assertJsonFragment(['employee_id' => $employee, 'date' => '2026-11-14']);
        $this->postJson('/api/attendances', ['employee_id' => $employee, 'action' => 'clockout'])->assertOk();
        $this->postJson('/api/shifts', ['employee_id' => $employee, 'starts_at' => '2026-11-20T07:00', 'ends_at' => '2026-11-20T15:00'])->assertUnprocessable();
    }

    public function test_future_attendance_and_invalid_profile_input_return_validation_errors(): void
    {
        $employee = DB::table('employees')->where('hotel_id', $this->hotel)->value('id');
        $this->postJson('/api/attendance-correction', ['employee_id' => $employee, 'date' => '2026-11-16', 'status' => 'present', 'clock_in' => '2026-11-16T23:59', 'notes' => 'Audit contoh'])->assertUnprocessable();
        $this->postJson('/api/account/profile', ['email' => ['invalid'], 'name' => 'Audit', 'current_password' => 'DemoHotel2026!'])->assertUnprocessable();
    }

    public function test_public_invalid_dates_recover_without_redirect_loop(): void
    {
        DB::table('hotels')->where('id', $this->hotel)->update(['public_booking' => true, 'public_slug' => 'audit-hotel']);
        $this->from('/booking/audit-hotel?arrival=invalid')->get('/booking/audit-hotel?arrival=invalid')->assertRedirect('/booking/audit-hotel');
        $this->get('/booking/audit-hotel')->assertOk()->assertSee('Hotel Contoh Utama');
    }

    public function test_password_bound_session_rejects_a_stale_password_hash(): void
    {
        $this->withSession(['password_hash_web' => 'old-password-hash'])->getJson('/api/data')->assertUnauthorized();
    }

    public function test_hotel_settings_permission_does_not_allow_journal_adjustments(): void
    {
        $role = DB::table('roles')->insertGetId(['hotel_id' => $this->hotel, 'name' => 'Audit pengaturan', 'permissions' => json_encode(['hotel.manage'])]);
        DB::table('hotel_user')->where('user_id', $this->owner->id)->where('hotel_id', $this->hotel)->update(['role_id' => $role]);
        foreach (['/api/ledger-accounts', '/api/ledger-adjustment', '/api/ledger-rebuild'] as $url) $this->postJson($url, [])->assertForbidden();
    }

    public function test_state_changes_require_a_valid_csrf_token(): void
    {
        $this->app->instance('env', 'local');
        $this->postJson('/api/expenses', ['description' => 'Tanpa CSRF', 'category' => 'Audit', 'amount' => 1000, 'date' => '2026-11-16'])->assertStatus(419);
        $this->withSession(['_token' => 'audit-csrf-token'])->withHeader('X-CSRF-TOKEN', 'audit-csrf-token')
            ->postJson('/api/expenses', ['description' => 'CSRF sah', 'category' => 'Audit', 'amount' => 1000, 'date' => '2026-11-16'])->assertOk();
        $this->assertDatabaseMissing('expenses', ['description' => 'Tanpa CSRF']);
    }

    public function test_passwords_reject_null_bytes_and_bcrypt_truncation(): void
    {
        foreach ([str_repeat('A', 73), str_repeat('é', 40), "ExamplePassword\0value"] as $password) {
            $this->postJson('/api/account/password', ['current_password' => 'DemoHotel2026!', 'password' => $password, 'password_confirmation' => $password])->assertUnprocessable();
        }
        $this->postJson('/api/members', ['name' => 'Audit', 'email' => 'audit@example.test', 'password' => str_repeat('B', 73), 'role_id' => DB::table('roles')->where('hotel_id', $this->hotel)->value('id')])->assertUnprocessable();
    }
}
