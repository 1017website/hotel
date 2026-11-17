<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class SaasSubscriptionTest extends TestCase
{
    use RefreshDatabase;
    private User $root;
    private int $plan;
    private int $sequence=0;

    protected function setUp():void
    {
        parent::setUp();$this->travelTo(Carbon::parse('2026-11-18 10:00:00','Asia/Jakarta'));$this->seed();
        $this->root=User::create(['name'=>'Root QA','email'=>'root-qa@example.test','password'=>'RootPassword123!']);
        DB::table('platform_admins')->insert(['user_id'=>$this->root->id,'is_super'=>true,'permissions'=>'[]','active'=>true]);
        $this->plan=DB::table('subscription_plans')->value('id');
        DB::table('subscription_plans')->where('id',$this->plan)->update(['name'=>'Paket QA','published'=>true,'monthly_price'=>120000,'yearly_price'=>1200000,'room_limit'=>2,'user_limit'=>2]);
        $this->actingAs($this->root);
    }

    private function tenant():array
    {
        $this->actingAs($this->root);$number=++$this->sequence;
        $this->postJson('/saas/api/tenants',['hotel_name'=>'Hotel SaaS QA '.$number,'name'=>'Owner QA '.$number,'email'=>'tenant'.$number.'@example.test','password'=>'TenantPassword123!','password_confirmation'=>'TenantPassword123!','plan_id'=>$this->plan,'months'=>1])->assertOk();
        $user=User::where('email','tenant'.$number.'@example.test')->first();$a=DB::table('saas_accounts')->where('owner_user_id',$user->id)->first();$i=DB::table('subscription_invoices')->where('hotel_id',$a->hotel_id)->first();return[$a,$user,$i];
    }

    private function activate(object $invoice):void
    {
        $this->actingAs($this->root)->postJson('/saas/api/invoices/'.$invoice->id,['action'=>'paid','reference'=>'QA-PAID','method'=>'Transfer'])->assertOk();
    }
    private function owner(User $user,int $hotel):void { $this->actingAs($user)->withSession(['hotel_id'=>$hotel]); }

    public function test_platform_and_hotel_accounts_are_separate():void
    {
        [$a,$owner]=$this->tenant();
        $this->getJson('/api/data')->assertForbidden();$this->get('/')->assertRedirect('/saas');
        $this->owner($owner,$a->hotel_id);
        $this->getJson('/saas/api/data')->assertForbidden();$this->postJson('/saas/api/admins',[])->assertForbidden();
        $this->get('/saas')->assertForbidden();
    }

    public function test_tenant_filter_matches_effective_access_and_search(): void
    {
        [$account, $owner, $invoice] = $this->tenant();
        $search = '/saas/api/data?q='.urlencode($owner->name).'&access=';
        $this->getJson($search.'pending')->assertOk()
            ->assertJsonPath('tenants.data.0.hotel_id', $account->hotel_id)
            ->assertJsonPath('tenants.data.0.access_state', 'pending');
        $this->getJson($search.'active')->assertOk()->assertJsonPath('tenants.total', 0);

        $this->activate($invoice);
        $this->getJson($search.'active')->assertOk()->assertJsonPath('tenants.data.0.access_state', 'active');

        DB::table('saas_accounts')->where('id', $account->id)->update(['status' => 'suspended', 'maintenance' => true]);
        $this->getJson($search.'suspended')->assertOk()->assertJsonPath('tenants.data.0.access_state', 'suspended');
        $this->getJson($search.'maintenance')->assertOk()->assertJsonPath('tenants.total', 0);

        DB::table('saas_settings')->where('id', 1)->update(['maintenance' => true]);
        $this->getJson($search.'suspended')->assertOk()->assertJsonPath('tenants.total', 0);
        $this->getJson($search.'maintenance')->assertOk()->assertJsonPath('tenants.data.0.access_state', 'maintenance');
    }

    public function test_pending_expired_and_suspended_access_is_checked_on_every_request():void
    {
        [$a,$owner,$invoice]=$this->tenant();$this->owner($owner,$a->hotel_id);
        $this->getJson('/api/data')->assertStatus(423)->assertJsonPath('state','pending');
        $this->get('/')->assertRedirect('/subscription');$this->get('/subscription')->assertOk()->assertSee('Menunggu aktivasi');$this->get('/account')->assertOk();
        $this->activate($invoice);$this->owner($owner,$a->hotel_id);$this->getJson('/api/data')->assertOk();
        $this->travelTo(Carbon::parse(DB::table('saas_accounts')->find($a->id)->ends_at,'UTC'));
        $this->getJson('/api/data')->assertStatus(423)->assertJsonPath('state','expired');
        $this->postJson('/api/rooms',['number'=>'BAD'])->assertStatus(423);$this->assertDatabaseMissing('rooms',['hotel_id'=>$a->hotel_id,'number'=>'BAD']);
    }

    public function test_owner_payment_report_does_not_activate_and_verification_is_idempotent():void
    {
        [$a,$owner,$i]=$this->tenant();$this->owner($owner,$a->hotel_id);
        $this->post('/subscription/invoices/'.$i->id.'/report',['reference'=>'QA-REPORT'])->assertRedirect();
        $this->assertDatabaseHas('saas_accounts',['id'=>$a->id,'status'=>'pending','ends_at'=>null]);
        $this->activate($i);$end=DB::table('saas_accounts')->find($a->id)->ends_at;$this->activate($i);
        $this->assertSame($end,DB::table('saas_accounts')->find($a->id)->ends_at);
        $this->assertEquals(Carbon::parse('2026-12-18 03:00:00','UTC')->toDateTimeString(),$end);
    }

    public function test_early_renewal_preserves_remaining_time_and_price_snapshots():void
    {
        [$a,$owner,$i]=$this->tenant();
        DB::table('subscription_plans')->where('id',$this->plan)->update(['name'=>'Harga baru','monthly_price'=>300000,'room_limit'=>8]);
        $this->activate($i);$account=DB::table('saas_accounts')->find($a->id);
        $this->assertEquals(2,$account->room_limit);$this->assertDatabaseHas('subscription_invoices',['id'=>$i->id,'amount'=>120000,'plan_name'=>'Paket QA']);
        $this->owner($owner,$a->hotel_id);$this->post('/subscription/renew',['months'=>1])->assertRedirect();$this->post('/subscription/renew',['months'=>1])->assertRedirect();
        $next=DB::table('subscription_invoices')->where('hotel_id',$a->hotel_id)->where('status','unpaid')->first();
        $this->assertSame(2,DB::table('subscription_invoices')->where('hotel_id',$a->hotel_id)->count());$this->assertEquals(300000,$next->amount);
        $this->activate($next);$this->assertDatabaseHas('saas_accounts',['id'=>$a->id,'ends_at'=>'2027-01-18 03:00:00','room_limit'=>8]);
    }

    public function test_payment_and_manual_extension_never_unsuspend_implicitly():void
    {
        [$a,$owner,$i]=$this->tenant();
        $this->postJson('/saas/api/tenants/'.$a->hotel_id.'/access',['action'=>'suspend','reason'=>'QA penangguhan'])->assertOk();
        $this->activate($i);$this->assertDatabaseHas('saas_accounts',['id'=>$a->id,'status'=>'suspended']);
        $this->postJson('/saas/api/tenants/'.$a->hotel_id.'/access',['action'=>'extend','request_key'=>(string)\Illuminate\Support\Str::uuid(),'days'=>3,'reason'=>'QA tambahan waktu'])->assertOk();
        $this->assertDatabaseHas('saas_accounts',['id'=>$a->id,'status'=>'suspended','ends_at'=>'2026-12-21 03:00:00']);
        $this->owner($owner,$a->hotel_id);$this->getJson('/api/data')->assertStatus(423)->assertJsonPath('state','suspended');
        $this->actingAs($this->root)->postJson('/saas/api/tenants/'.$a->hotel_id.'/access',['action'=>'unsuspend','reason'=>'QA dibuka kembali'])->assertOk();
        $this->owner($owner,$a->hotel_id);$this->getJson('/api/data')->assertOk();
    }

    public function test_maintenance_is_scheduled_and_does_not_lock_out_billing_or_platform():void
    {
        [$a,$owner,$i]=$this->tenant();$this->activate($i);
        $until=now('Asia/Jakarta')->addHour()->format('Y-m-d\TH:i');
        $this->postJson('/saas/api/tenants/'.$a->hotel_id.'/access',['action'=>'maintenance_on','reason'=>'QA pemeliharaan','until'=>$until])->assertOk();
        $this->owner($owner,$a->hotel_id);$this->getJson('/api/data')->assertStatus(503);$this->get('/subscription')->assertOk();
        $this->travel(2)->hours();$this->getJson('/api/data')->assertOk();
        $this->actingAs($this->root)->putJson('/saas/api/maintenance',['enabled'=>1,'reason'=>'QA global','until'=>now('Asia/Jakarta')->addHour()->format('Y-m-d\TH:i')])->assertOk();
        $this->getJson('/saas/api/data')->assertOk();$this->owner($owner,$a->hotel_id);$this->getJson('/api/data')->assertStatus(503);$this->get('/subscription')->assertOk();
        $this->actingAs($this->root)->putJson('/saas/api/maintenance',['enabled'=>0,'reason'=>'QA selesai','until'=>'2026-01-01T01:00'])->assertOk();
    }

    public function test_public_booking_is_blocked_when_subscription_is_unavailable():void
    {
        [$a,$owner,$i]=$this->tenant();DB::table('hotels')->where('id',$a->hotel_id)->update(['public_booking'=>true,'public_slug'=>'qa-saas']);
        $this->get('/booking/qa-saas')->assertStatus(423)->assertSee('Pemesanan hotel sedang tidak tersedia')->assertDontSee($owner->email);
        $this->activate($i);$this->get('/booking/qa-saas')->assertOk();
        $this->postJson('/saas/api/tenants/'.$a->hotel_id.'/access',['action'=>'suspend','reason'=>'QA suspend'])->assertOk();
        $this->post('/booking/qa-saas',[])->assertStatus(423);$this->assertSame(0,DB::table('reservations')->where('hotel_id',$a->hotel_id)->count());
    }

    public function test_plan_limits_are_enforced_for_rooms_and_user_accounts():void
    {
        [$a,$owner,$i]=$this->tenant();$this->activate($i);$this->owner($owner,$a->hotel_id);
        $room=['type'=>'Standar QA','floor'=>1,'capacity'=>2,'rate'=>100000,'facilities'=>'AC'];
        $this->postJson('/api/rooms',[...$room,'number'=>'101'])->assertOk();$this->postJson('/api/rooms',[...$room,'number'=>'102'])->assertOk();$this->postJson('/api/rooms',[...$room,'number'=>'103'])->assertUnprocessable();
        $role=DB::table('roles')->where('hotel_id',$a->hotel_id)->value('id');
        $member=['name'=>'Staf QA','password'=>'StaffPassword123!','role_id'=>$role];
        $this->postJson('/api/members',[...$member,'email'=>'staff1@example.test'])->assertOk();$this->postJson('/api/members',[...$member,'email'=>'staff2@example.test'])->assertUnprocessable();
        $this->assertSame(2,DB::table('hotel_user')->where('hotel_id',$a->hotel_id)->count());
    }

    public function test_billing_is_owner_scoped_and_does_not_leak_to_staff_or_other_hotels():void
    {
        [$a,$owner,$i]=$this->tenant();[$other,$otherOwner,$otherInvoice]=$this->tenant();
        $this->owner($owner,$a->hotel_id);$this->get('/subscription/invoices/'.$otherInvoice->id)->assertNotFound();
        $this->post('/subscription/invoices/'.$otherInvoice->id.'/report',['reference'=>'QA-FOREIGN'])->assertNotFound();
        $staff=User::create(['name'=>'Staf QA','email'=>'billing-staff@example.test','password'=>'StaffPassword123!']);$role=DB::table('roles')->where('hotel_id',$a->hotel_id)->value('id');DB::table('hotel_user')->insert(['hotel_id'=>$a->hotel_id,'user_id'=>$staff->id,'role_id'=>$role]);
        $this->owner($staff,$a->hotel_id);$this->get('/subscription')->assertOk()->assertDontSee($i->code);$this->get('/subscription/invoices/'.$i->id)->assertForbidden();$this->post('/subscription/renew',['months'=>1])->assertForbidden();
    }

    public function test_sub_admin_is_limited_and_super_admin_cannot_be_changed_from_ui():void
    {
        $this->postJson('/saas/api/admins',['name'=>'Ops QA','email'=>'ops-qa@example.test','password'=>'OpsPassword123!','password_confirmation'=>'OpsPassword123!','permissions'=>['subscriptions.manage'],'active'=>1,'is_super'=>1])->assertOk();
        $ops=User::where('email','ops-qa@example.test')->first();$admin=DB::table('platform_admins')->where('user_id',$ops->id)->first();$this->assertFalse((bool)$admin->is_super);
        [$a]=$this->tenant();$this->actingAs($ops)->getJson('/saas/api/data')->assertOk()->assertJsonCount(0,'admins');
        $this->postJson('/saas/api/plans',[])->assertForbidden();$this->postJson('/saas/api/admins',[])->assertForbidden();$this->putJson('/saas/api/settings',[])->assertForbidden();
        $this->postJson('/saas/api/tenants/'.$a->hotel_id.'/access',['action'=>'extend','request_key'=>(string)\Illuminate\Support\Str::uuid(),'days'=>1,'reason'=>'QA aktivasi'])->assertOk();
        $this->getJson('/api/data')->assertForbidden();
        $this->actingAs($this->root);$root=DB::table('platform_admins')->where('user_id',$this->root->id)->value('id');$this->putJson('/saas/api/admins/'.$root,['permissions'=>[],'active'=>0])->assertUnprocessable();
        $this->putJson('/saas/api/admins/'.$admin->id,['permissions'=>[],'active'=>0])->assertOk();$this->actingAs($ops)->getJson('/saas/api/data')->assertForbidden();
    }

    public function test_signup_creates_one_owner_one_hotel_and_pending_invoice():void
    {
        DB::table('saas_settings')->where('id',1)->update(['signup_enabled'=>true]);$this->post('/logout');
        $d=['hotel_name'=>'Hotel Daftar QA','name'=>'Owner Daftar','email'=>'register-qa@example.test','password'=>'SignupPassword123!','password_confirmation'=>'SignupPassword123!','plan_id'=>$this->plan,'months'=>12];
        $this->get('/register')->assertOk();$this->post('/register',$d)->assertRedirect('/subscription');
        $owner=User::where('email',$d['email'])->first();$this->assertAuthenticatedAs($owner);$this->assertSame(1,DB::table('hotel_user')->where('user_id',$owner->id)->count());$this->assertSame(1,DB::table('saas_accounts')->where('owner_user_id',$owner->id)->count());
        $this->assertDatabaseHas('subscription_invoices',['months'=>12,'amount'=>1200000,'status'=>'unpaid']);$this->getJson('/api/data')->assertStatus(423);
        $this->post('/logout');$this->postJson('/register',$d)->assertUnprocessable();
    }

    public function test_unpaid_invoices_can_be_voided_and_cannot_be_paid_after_void():void
    {
        [$a,$owner,$i]=$this->tenant();$this->postJson('/saas/api/invoices/'.$i->id,['action'=>'void','reference'=>'QA batal'])->assertOk();$this->postJson('/saas/api/invoices/'.$i->id,['action'=>'paid','reference'=>'QA-PAY','method'=>'Transfer'])->assertUnprocessable();$this->assertDatabaseHas('saas_accounts',['id'=>$a->id,'status'=>'pending']);
    }

    public function test_password_recovery_uses_single_use_token_and_revokes_sessions():void
    {
        [$a,$owner]=$this->tenant();Notification::fake();$this->post('/logout');
        $this->post('/forgot-password',['email'=>$owner->email])->assertRedirect();Notification::assertSentTo($owner,ResetPassword::class);
        $token=Password::createToken($owner);DB::table('sessions')->insert(['id'=>'old-qa-session','user_id'=>$owner->id,'payload'=>'test','last_activity'=>time()]);
        $d=['email'=>$owner->email,'token'=>$token,'password'=>'RecoveredPassword123!','password_confirmation'=>'RecoveredPassword123!'];
        $this->post('/reset-password',$d)->assertRedirect('/login');$this->assertTrue(Hash::check($d['password'],$owner->fresh()->password));$this->assertDatabaseMissing('sessions',['id'=>'old-qa-session']);$this->post('/reset-password',$d)->assertSessionHasErrors();
    }

    public function test_manual_extension_retries_only_add_days_once():void
    {
        [$a]=$this->tenant();$d=['action'=>'extend','days'=>'7','reason'=>'QA masa uji','request_key'=>(string)\Illuminate\Support\Str::uuid()];
        $this->postJson('/saas/api/tenants/'.$a->hotel_id.'/access',$d)->assertOk();$end=DB::table('saas_accounts')->find($a->id)->ends_at;
        $this->postJson('/saas/api/tenants/'.$a->hotel_id.'/access',$d)->assertOk();
        $this->assertSame($end,DB::table('saas_accounts')->find($a->id)->ends_at);$this->assertDatabaseCount('subscription_adjustments',1);
    }

    public function test_draft_or_zero_price_plans_cannot_be_sold_and_owner_cannot_own_two_accounts():void
    {
        $this->postJson('/saas/api/plans',['name'=>'Nol QA','slug'=>'nol-qa','description'=>'QA','monthly_price'=>0,'yearly_price'=>0,'room_limit'=>1,'user_limit'=>1,'published'=>1])->assertUnprocessable();
        [$a,$owner]=$this->tenant();$other=DB::table('hotels')->where('id','!=',$a->hotel_id)->value('id');
        try{(new SubscriptionService)->attach($other,$owner->id,$this->plan);$this->fail('Owner yang sama tidak boleh memiliki dua hotel SaaS.');}catch(\Illuminate\Validation\ValidationException){$this->assertSame(1,DB::table('saas_accounts')->where('owner_user_id',$owner->id)->count());}
    }
}
