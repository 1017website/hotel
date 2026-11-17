<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private int $hotel;
    private object $employee;
    private object $second;
    private User $owner;
    private string $header = "kode_mesin,tanggal_kerja,jam_masuk,jam_pulang\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00', 'Asia/Jakarta'));
        $this->seed();
        $this->hotel = DB::table('hotels')->min('id');
        $employees = DB::table('employees')->where('hotel_id', $this->hotel)->orderBy('id')->get();
        $this->employee = $employees[0]; $this->second = $employees[1];
        DB::table('employees')->where('id', $this->employee->id)->update(['attendance_code' => '001']);
        DB::table('employees')->where('id', $this->second->id)->update(['attendance_code' => '002']);
        $this->owner = User::where('email', 'owner@example.test')->first();
        $this->actingAs($this->owner)->withSession(['hotel_id' => $this->hotel]);
    }

    private function selfUser(): User
    {
        $user = User::where('email', 'housekeeping@example.test')->first();
        $role = DB::table('roles')->insertGetId(['hotel_id' => $this->hotel, 'name' => 'Absensi pribadi', 'permissions' => '["attendance.self"]']);
        DB::table('hotel_user')->where('hotel_id', $this->hotel)->where('user_id', $user->id)->update(['role_id' => $role]);
        DB::table('employees')->where('id', $this->employee->id)->update(['user_id' => $user->id]);
        $this->actingAs($user);
        return $user;
    }

    private function preview(string $content)
    {
        return $this->post('/api/attendance-import/preview', ['file' => UploadedFile::fake()->createWithContent('mesin.csv', $content)], ['Accept' => 'application/json']);
    }

    private function commit(string $content, string $token)
    {
        return $this->post('/api/attendance-import/commit', ['file' => UploadedFile::fake()->createWithContent('mesin.csv', $content), 'preview_token' => $token], ['Accept' => 'application/json']);
    }

    public function test_employee_clocks_only_self_without_team_data_and_uses_server_time(): void
    {
        $this->selfUser();
        $this->getJson('/api/my-attendance')->assertOk()->assertJsonPath('employee.id', $this->employee->id)->assertJsonCount(0, 'records');
        $this->getJson('/api/data')->assertJsonMissingPath('employees')->assertJsonMissingPath('members');
        $this->getJson('/api/workforce?date=2026-11-18')->assertForbidden();
        $this->postJson('/api/attendances', ['employee_id' => $this->second->id])->assertForbidden();
        $this->postJson('/api/my-attendance', ['action' => 'clockin', 'employee_id' => $this->second->id])->assertUnprocessable();
        $this->postJson('/api/my-attendance', ['action' => 'clockin', 'clock_in' => '2026-11-18 01:00:00'])->assertUnprocessable();
        $this->postJson('/api/my-attendance', ['action' => 'clockin'])->assertOk();
        $this->postJson('/api/my-attendance', ['action' => 'clockin'])->assertUnprocessable();
        $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee->id, 'clock_in' => '2026-11-18 10:00:00', 'source' => 'self']);
        $this->travel(8)->hours();
        $this->postJson('/api/my-attendance', ['action' => 'clockout'])->assertOk();
        $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee->id, 'clock_out' => '2026-11-18 18:00:00']);
    }

    public function test_self_overnight_and_inactive_open_record_can_be_completed(): void
    {
        $this->selfUser(); $this->travelTo(Carbon::parse('2026-11-18 22:00', 'Asia/Jakarta'));
        $this->postJson('/api/my-attendance', ['action' => 'clockin'])->assertOk();
        DB::table('employees')->where('id', $this->employee->id)->update(['active' => false]);
        $this->travel(8)->hours();
        $this->getJson('/api/my-attendance')->assertJsonPath('open.date', '2026-11-18');
        $this->postJson('/api/my-attendance', ['action' => 'clockout'])->assertOk();
        $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee->id, 'date' => '2026-11-18', 'clock_out' => '2026-11-19 06:00:00']);
        $this->postJson('/api/my-attendance', ['action' => 'clockin'])->assertUnprocessable();
    }

    public function test_mapping_is_unique_tenant_scoped_and_account_mapping_requires_user_management(): void
    {
        $user = User::where('email', 'housekeeping@example.test')->first();
        $this->putJson('/api/employee-attendance/'.$this->employee->id, ['attendance_code' => 'abc-001', 'user_id' => $user->id])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $this->employee->id, 'attendance_code' => 'ABC-001', 'user_id' => $user->id]);
        $this->putJson('/api/employee-attendance/'.$this->second->id, ['attendance_code' => 'ABC-001'])->assertUnprocessable();
        $this->putJson('/api/employee-attendance/'.$this->second->id, ['attendance_code' => '002', 'user_id' => $user->id])->assertUnprocessable();
        $foreign = DB::table('employees')->where('hotel_id', '!=', $this->hotel)->value('id');
        $this->putJson('/api/employee-attendance/'.$foreign, ['attendance_code' => 'OTHER'])->assertNotFound();
        $role = DB::table('roles')->insertGetId(['hotel_id' => $this->hotel, 'name' => 'HR tanpa akun', 'permissions' => '["employees.manage"]']);
        DB::table('hotel_user')->where('user_id', $this->owner->id)->where('hotel_id', $this->hotel)->update(['role_id' => $role]);
        $this->putJson('/api/employee-attendance/'.$this->second->id, ['attendance_code' => '0002'])->assertOk();
        $this->putJson('/api/employee-attendance/'.$this->second->id, ['attendance_code' => '0002', 'user_id' => null])->assertForbidden();
    }

    public function test_unlinked_account_and_missing_permissions_cannot_punch_or_import(): void
    {
        $this->getJson('/api/my-attendance')->assertJsonPath('employee', null);
        $this->postJson('/api/my-attendance', ['action' => 'clockin'])->assertUnprocessable();
        $this->actingAs(User::where('email', 'housekeeping@example.test')->first());
        $this->getJson('/api/my-attendance')->assertForbidden();
        $this->postJson('/api/my-attendance', ['action' => 'clockin'])->assertForbidden();
        $this->preview($this->header."001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n")->assertForbidden();
        $this->get('/attendance/template.csv')->assertForbidden();
        $this->getJson('/api/attendance-imports')->assertForbidden();
    }

    public function test_preview_import_overnight_and_retries_do_not_duplicate_rows(): void
    {
        $content = $this->header."001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n002,2026-11-17,2026-11-17 22:00:00,2026-11-18 06:00:00\n";
        $preview = $this->preview($content)->assertOk()->assertJsonPath('valid', true)->assertJsonPath('counts.new', 2)->json();
        $this->assertDatabaseCount('attendance_imports', 0);
        $this->commit($content, $preview['token'])->assertOk()->assertJsonPath('batch.inserted', 2);
        $this->commit($content, $preview['token'])->assertOk();
        $this->assertDatabaseCount('attendance_imports', 1);
        $this->assertSame(2, DB::table('attendances')->where('source', 'fingerprint')->count());
        $again = "\xEF\xBB\xBF".str_replace(',', ';', $content);
        $preview = $this->preview($again)->assertOk()->assertJsonPath('counts.skip', 2)->json();
        $this->commit($again, $preview['token'])->assertOk()->assertJsonPath('batch.inserted', 0);
        $this->assertSame(2, DB::table('attendances')->where('source', 'fingerprint')->count());
    }

    public function test_later_file_completes_open_machine_record_before_following_shift(): void
    {
        $first = $this->header."001,2026-11-16,2026-11-16 22:00:00,\n";
        $token = $this->preview($first)->assertOk()->json('token');
        $this->commit($first, $token)->assertOk();
        $next = $this->header."001,2026-11-16,2026-11-16 22:00:00,2026-11-17 06:00:00\n001,2026-11-17,2026-11-17 22:00:00,2026-11-18 06:00:00\n";
        $preview = $this->preview($next)->assertOk()->assertJsonPath('valid', true)->assertJsonPath('counts.complete', 1)->assertJsonPath('counts.new', 1)->json();
        $this->commit($next, $preview['token'])->assertOk()->assertJsonPath('batch.completed', 1);
        $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee->id, 'date' => '2026-11-16', 'clock_out' => '2026-11-17 06:00:00']);
    }

    public function test_invalid_rows_future_time_unknown_mapping_and_conflicts_block_entire_file(): void
    {
        $content = $this->header."001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n999,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n";
        $this->preview($content)->assertOk()->assertJsonPath('valid', false)->assertJsonPath('counts.error', 1)->assertJsonPath('token', null);
        $this->commit($content, 'invalid-token')->assertUnprocessable();
        $this->assertSame(0, DB::table('attendances')->where('source', 'fingerprint')->count());
        $this->preview($this->header."001,2026-11-18,2026-11-18 12:00:00,\n")->assertJsonPath('valid', false);
        $this->preview($this->header."001,2026-02-30,2026-02-30 07:00:00,\n")->assertJsonPath('valid', false);
        DB::table('attendances')->insert(['hotel_id' => $this->hotel, 'employee_id' => $this->employee->id, 'date' => '2026-11-16', 'status' => 'leave']);
        $this->preview($this->header."001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n")->assertJsonPath('valid', false);
    }

    public function test_commit_rechecks_preview_file_mapping_conflicts_and_expiration(): void
    {
        $content = $this->header."001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n";
        $token = $this->preview($content)->assertOk()->json('token');
        $this->commit(str_replace('15:00:00', '16:00:00', $content), $token)->assertUnprocessable();
        DB::table('employees')->where('id', $this->employee->id)->update(['attendance_code' => null]);
        DB::table('employees')->where('id', $this->second->id)->update(['attendance_code' => '001']);
        $this->commit($content, $token)->assertUnprocessable();
        $this->assertDatabaseCount('attendance_imports', 0);
        $token = $this->preview($content)->assertOk()->json('token');
        $this->travel(16)->minutes();
        $this->commit($content, $token)->assertUnprocessable();
    }

    public function test_overlap_and_conflicting_duplicate_dates_are_not_imported(): void
    {
        $content = $this->header."001,2026-11-16,2026-11-16 22:00:00,2026-11-17 08:00:00\n001,2026-11-17,2026-11-17 07:00:00,2026-11-17 15:00:00\n";
        $this->preview($content)->assertOk()->assertJsonPath('valid', false)->assertJsonPath('counts.error', 2);
        $content = $this->header."001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n001,2026-11-16,2026-11-16 07:00:00,2026-11-16 16:00:00\n";
        $this->preview($content)->assertJsonPath('valid', false);
        $identical = $this->header.str_repeat("001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n", 2);
        $this->preview($identical)->assertJsonPath('valid', true)->assertJsonPath('counts.new', 1)->assertJsonPath('counts.skip', 1);
    }

    public function test_import_never_overwrites_a_changed_manual_record_and_history_is_scoped(): void
    {
        $content = $this->header."001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n";
        $token = $this->preview($content)->assertOk()->json('token');
        $this->postJson('/api/attendance-correction', ['employee_id' => $this->employee->id, 'date' => '2026-11-16', 'status' => 'present', 'clock_in' => '2026-11-16 08:00:00', 'clock_out' => '2026-11-16 15:00:00', 'notes' => 'Contoh koreksi'])->assertOk();
        $this->commit($content, $token)->assertUnprocessable();
        $this->assertDatabaseHas('attendances', ['employee_id' => $this->employee->id, 'clock_in' => '2026-11-16 08:00:00', 'source' => 'correction']);
        $this->assertDatabaseCount('attendance_imports', 0);
        $other = DB::table('hotels')->where('id', '!=', $this->hotel)->value('id');
        DB::table('attendance_imports')->insert(['hotel_id' => $other, 'user_id' => $this->owner->id, 'filename' => 'hotel-lain.csv', 'file_hash' => str_repeat('a', 64)]);
        $this->getJson('/api/attendance-imports')->assertOk()->assertJsonCount(0, 'batches');
        $this->get('/attendance/template.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee('kode_mesin,tanggal_kerja,jam_masuk,jam_pulang');
    }

    public function test_preview_tokens_and_device_codes_cannot_cross_hotels(): void
    {
        $other = DB::table('hotels')->where('id', '!=', $this->hotel)->value('id');
        DB::table('employees')->where('hotel_id', $other)->orderBy('id')->limit(1)->update(['attendance_code' => 'FOREIGN']);
        $this->preview($this->header."FOREIGN,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n")->assertOk()->assertJsonPath('valid', false);
        $content = $this->header."001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n";
        $token = $this->preview($content)->assertOk()->json('token');
        $this->withSession(['hotel_id' => $other]);
        $this->commit($content, $token)->assertUnprocessable();
        $this->assertDatabaseCount('attendance_imports', 0);
    }

    public function test_import_size_row_limit_and_header_are_enforced(): void
    {
        $this->preview("PIN,waktu\n001,2026-11-16\n")->assertUnprocessable();
        $this->preview($this->header.str_repeat('A', 41).",2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n")->assertOk()->assertJsonPath('valid', false);
        $content = $this->header.str_repeat("001,2026-11-16,2026-11-16 07:00:00,2026-11-16 15:00:00\n", 1001);
        $this->preview($content)->assertUnprocessable();
        $this->post('/api/attendance-import/preview', ['file' => UploadedFile::fake()->create('mesin.csv', 2050)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('attendance_imports', 0);
    }
}
