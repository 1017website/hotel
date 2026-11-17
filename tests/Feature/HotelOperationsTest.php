<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HotelOperationsTest extends TestCase
{
    use RefreshDatabase;

    private int $hotel;
    private int $room;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(3, 0));
        $this->seed();
        $this->hotel = DB::table('hotels')->min('id');
        $this->room = DB::table('rooms')->where('hotel_id', $this->hotel)->where('number', '204')->value('id');
        $this->owner = User::where('email', 'owner@example.test')->first();
        $this->actingAs($this->owner)->withSession(['hotel_id' => $this->hotel]);
    }

    private function booking(array $changes = [])
    {
        return $this->postJson('/api/reservations', [...[
            'room_id' => $this->room, 'guest' => 'Tamu Pengujian', 'arrival' => '2026-11-15',
            'departure' => '2026-11-17', 'adults' => 2, 'source' => 'Langsung',
        ], ...$changes]);
    }

    public function test_guest_must_sign_in_and_login_regenerates_authenticated_session(): void
    {
        auth()->logout();
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/data')->assertUnauthorized();
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'DemoHotel2026!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_overlap_is_rejected_but_checkout_boundary_is_available(): void
    {
        $this->booking()->assertOk();
        $this->booking(['arrival' => '2026-11-16', 'departure' => '2026-11-18'])->assertUnprocessable();
        $this->booking(['arrival' => '2026-11-17', 'departure' => '2026-11-18'])->assertOk();
        $this->assertSame(2, DB::table('reservations')->where('room_id', $this->room)->count());
    }

    public function test_tenant_isolation_applies_to_reads_writes_and_relationships(): void
    {
        $otherRoom = DB::table('rooms')->where('hotel_id', '!=', $this->hotel)->value('id');
        $this->booking(['room_id' => $otherRoom])->assertUnprocessable();
        $this->putJson('/api/rooms/'.$otherRoom, ['housekeeping' => 'clean'])->assertNotFound();
        $data = $this->getJson('/api/data')->assertOk()->json();
        foreach ($data['rooms'] as $room) { $this->assertSame($this->hotel, $room['hotel_id']); }
        $cleaner = User::where('email', 'housekeeping@example.test')->first();
        $this->actingAs($cleaner)->postJson('/api/hotel/switch', ['hotel_id' => DB::table('hotels')->max('id')])->assertForbidden();
    }

    public function test_housekeeping_has_no_guest_financial_or_management_access(): void
    {
        $this->actingAs(User::where('email', 'housekeeping@example.test')->first());
        $data = $this->getJson('/api/data')->assertOk()->json();
        $this->assertArrayNotHasKey('reservations', $data);
        $this->assertArrayNotHasKey('finance', $data);
        $this->assertArrayNotHasKey('members', $data);
        $this->assertArrayNotHasKey('rate', $data['rooms'][0]);
        $this->assertNull($data['rooms'][0]['reservation']);
        $this->booking()->assertForbidden();
        $this->postJson('/api/expenses', [])->assertForbidden();
        $this->putJson('/api/rooms/'.$this->room, ['housekeeping' => 'cleaning'])->assertOk();
    }

    public function test_housekeeping_quick_action_cannot_skip_or_repeat_a_step(): void
    {
        $this->actingAs(User::where('email', 'housekeeping@example.test')->first());
        DB::table('rooms')->where('id', $this->room)->update(['housekeeping' => 'dirty', 'assigned_to' => 'Petugas Uji']);
        $url = '/api/rooms/'.$this->room;
        $this->putJson($url, ['housekeeping' => 'clean', 'advance_housekeeping' => true])->assertConflict();
        $this->putJson($url, ['housekeeping' => 'cleaning', 'advance_housekeeping' => true])->assertOk();
        $this->putJson($url, ['housekeeping' => 'cleaning', 'advance_housekeeping' => true])->assertConflict();
        $this->putJson($url, ['housekeeping' => 'inspection', 'advance_housekeeping' => true])->assertOk();
        $this->putJson($url, ['housekeeping' => 'clean', 'advance_housekeeping' => true])->assertOk();
        $this->assertDatabaseHas('rooms', ['id' => $this->room, 'housekeeping' => 'clean', 'assigned_to' => 'Petugas Uji']);
    }

    public function test_checkin_requires_clean_room_and_checkout_requires_full_payment(): void
    {
        $this->booking()->assertOk();
        $id = DB::table('reservations')->max('id');
        DB::table('rooms')->where('id', $this->room)->update(['housekeeping' => 'dirty']);
        $this->putJson('/api/reservations/'.$id, ['action' => 'checkin'])->assertUnprocessable();
        $this->putJson('/api/rooms/'.$this->room, ['housekeeping' => 'clean'])->assertOk();
        $this->postJson('/api/guests/'.$id,['guest'=>'Tamu Pengujian','identity_type'=>'Paspor','identity_number'=>'TEST-00001'])->assertOk();
        $this->putJson('/api/reservations/'.$id, ['action' => 'checkin'])->assertOk();
        $this->putJson('/api/rooms/'.$this->room, ['housekeeping' => 'cleaning'])->assertUnprocessable();
        $this->putJson('/api/reservations/'.$id, ['action' => 'checkout'])->assertUnprocessable();
        $total = DB::table('reservations')->where('id', $id)->value('total');
        $this->putJson('/api/payments/'.$id, ['amount' => $total + 1, 'method' => 'Tunai'])->assertUnprocessable();
        $this->putJson('/api/payments/'.$id, ['amount' => $total, 'method' => 'Tunai'])->assertOk();
        $this->putJson('/api/payments/'.$id, ['amount' => 1, 'method' => 'Tunai'])->assertUnprocessable();
        $this->putJson('/api/reservations/'.$id, ['action' => 'checkout'])->assertOk();
        $this->assertDatabaseHas('rooms', ['id' => $this->room, 'housekeeping' => 'dirty']);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'checked_out']);
    }

    public function test_food_is_priced_server_side_and_added_once_to_guest_bill(): void
    {
        $this->booking()->assertOk();
        $id = DB::table('reservations')->max('id');
        $this->postJson('/api/guests/'.$id,['guest'=>'Tamu Pengujian','identity_type'=>'Paspor','identity_number'=>'TEST-00001'])->assertOk();
        $this->putJson('/api/reservations/'.$id, ['action' => 'checkin'])->assertOk();
        $before = DB::table('reservations')->where('id', $id)->value('total');
        $item = DB::table('food_items')->where('hotel_id', $this->hotel)->first();
        $this->postJson('/api/food_orders', ['reservation_id' => $id, 'food_item_id' => $item->id, 'quantity' => 2, 'total' => 1])->assertOk();
        $this->assertEquals($before + $item->price * 2, DB::table('reservations')->where('id', $id)->value('total'));
        $order = DB::table('food_orders')->max('id');
        $this->putJson('/api/food_orders/'.$order, ['status' => 'served'])->assertUnprocessable();
        foreach (['preparing', 'delivering', 'served'] as $status) { $this->putJson('/api/food_orders/'.$order, ['status' => $status])->assertOk(); }
        $this->assertEquals($before + $item->price * 2, DB::table('reservations')->where('id', $id)->value('total'));
    }

    public function test_paid_reservation_cannot_be_cancelled_without_refund_workflow(): void
    {
        $this->booking()->assertOk(); $id = DB::table('reservations')->max('id');
        $this->putJson('/api/payments/'.$id, ['amount' => 100000, 'method' => 'Transfer'])->assertOk();
        $this->putJson('/api/reservations/'.$id, ['action' => 'cancel'])->assertUnprocessable();
    }

    public function test_maintenance_blocks_booking_until_all_tickets_are_resolved(): void
    {
        foreach (['AC rusak', 'Pintu rusak'] as $description) { $this->postJson('/api/maintenance', ['room_id' => $this->room, 'description' => $description])->assertOk(); }
        $this->booking()->assertUnprocessable();
        $ids = DB::table('maintenance')->where('room_id', $this->room)->pluck('id');
        $this->putJson('/api/maintenance/'.$ids[0], ['status' => 'resolved'])->assertOk();
        $this->assertDatabaseHas('rooms', ['id' => $this->room, 'out_of_service' => 1]);
        $this->putJson('/api/maintenance/'.$ids[1], ['status' => 'resolved'])->assertOk();
        $this->assertDatabaseHas('rooms', ['id' => $this->room, 'out_of_service' => 0, 'housekeeping' => 'dirty']);
    }

    public function test_overnight_shift_and_attendance_are_supported(): void
    {
        $employee = DB::table('employees')->where('hotel_id', $this->hotel)->value('id');
        $shift = ['employee_id' => $employee, 'starts_at' => '2026-11-15T22:00', 'ends_at' => '2026-11-16T06:00'];
        $this->postJson('/api/shifts', $shift)->assertOk();
        $this->postJson('/api/shifts', [...$shift, 'starts_at' => '2026-11-16T03:00'])->assertUnprocessable();
        $this->postJson('/api/attendances', ['employee_id' => $employee])->assertOk();
        $this->postJson('/api/attendances', ['employee_id' => $employee])->assertUnprocessable();
        $this->travel(1)->days();
        $this->postJson('/api/attendances', ['employee_id' => $employee, 'action' => 'clockout'])->assertOk();
        $this->assertNotNull(DB::table('attendances')->where('employee_id', $employee)->value('clock_out'));
    }

    public function test_expenses_require_approval_and_owner_role_cannot_be_removed(): void
    {
        $this->postJson('/api/expenses', ['description' => 'Biaya uji', 'category' => 'Operasional', 'amount' => 50000, 'date' => '2026-11-15'])->assertOk();
        $id = DB::table('expenses')->max('id');
        $this->getJson('/api/data')->assertJsonPath('finance.expenses', 0);
        $this->putJson('/api/expenses/'.$id, ['status' => 'approved'])->assertOk();
        $this->getJson('/api/data')->assertJsonPath('finance.expenses', 50000);
        $this->putJson('/api/expenses/'.$id, ['status' => 'rejected'])->assertUnprocessable();
        $role = DB::table('roles')->where('hotel_id', $this->hotel)->where('is_owner', true)->value('id');
        $this->putJson('/api/roles/'.$role, ['permissions' => []])->assertUnprocessable();
    }

    public function test_channel_secrets_are_encrypted_and_never_returned_to_browser(): void
    {
        $id = DB::table('channel_connections')->where('hotel_id', $this->hotel)->value('id');
        $secret = 'test-only-secret';
        $this->putJson('/api/channels/'.$id, ['property_id' => 'TEST123', 'connection_mode' => 'direct', 'credentials' => $secret, 'mapping' => '[]'])->assertOk();
        $stored = DB::table('channel_connections')->where('id', $id)->value('credentials');
        $this->assertNotSame($secret, $stored); $this->assertSame($secret, decrypt($stored));
        $response = $this->getJson('/api/data')->assertOk();
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertArrayNotHasKey('credentials', $response->json('channels.0'));
        $this->assertDatabaseHas('channel_connections', ['id' => $id, 'status' => 'pending_verification', 'last_synced_at' => null]);
    }

    public function test_room_rate_edits_preserve_existing_booking_prices_and_invoice_is_scoped(): void
    {
        $this->booking()->assertOk();
        $booking = DB::table('reservations')->latest('id')->first();
        $room = DB::table('rooms')->find($this->room);
        $this->putJson('/api/room_details/'.$room->id, ['number' => $room->number, 'type' => 'Suite Baru', 'floor' => 2, 'capacity' => 4, 'rate' => 1000000, 'facilities' => 'AC, Wi-Fi'])->assertOk();
        $this->assertDatabaseHas('reservations', ['id' => $booking->id, 'total' => $booking->total]);
        $this->get('/reservations/'.$booking->id.'/invoice')->assertOk()->assertSee('Tamu Pengujian');
        $this->withSession(['hotel_id' => DB::table('hotels')->max('id')])->get('/reservations/'.$booking->id.'/invoice')->assertNotFound();
        $this->actingAs(User::where('email', 'housekeeping@example.test')->first())->withSession(['hotel_id' => $this->hotel])->get('/reservations/'.$booking->id.'/invoice')->assertForbidden();
    }

    public function test_custom_role_user_and_non_demo_hotel_can_be_provisioned(): void
    {
        $this->postJson('/api/roles', ['name' => 'Pemeriksa', 'permissions' => ['rooms.view']])->assertOk();
        $role = DB::table('roles')->where('name', 'Pemeriksa')->value('id');
        $this->postJson('/api/members', ['name' => 'Pengguna Uji', 'email' => 'uji@example.test', 'password' => 'TestingHotel123!', 'role_id' => $role])->assertOk();
        $user = User::where('email', 'uji@example.test')->first();
        $this->actingAs($user)->getJson('/api/data')->assertOk()->assertJsonPath('permissions', ['rooms.view']);
        $this->actingAs($user)->putJson('/api/room_details/'.$this->room, [])->assertForbidden();
        $this->actingAs($this->owner)->postJson('/api/roles', ['name' => 'Petugas Kebersihan Baru', 'permissions' => ['housekeeping.manage']])->assertOk();
        $permissions = json_decode(DB::table('roles')->where('name', 'Petugas Kebersihan Baru')->value('permissions'), true);
        $this->assertContains('rooms.view', $permissions);
        $this->artisan('hotel:create', ['name' => 'Hotel Uji Baru', 'email' => 'newowner@example.test'])
            ->expectsQuestion('Nama pemilik', 'Owner Baru')->expectsQuestion('Kata sandi pemilik (minimal 12 karakter)', 'OwnerBaru123!')->assertSuccessful();
        $this->assertDatabaseHas('hotels', ['name' => 'Hotel Uji Baru', 'is_demo' => false]);
    }
}
