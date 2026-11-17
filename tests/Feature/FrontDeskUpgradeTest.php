<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;
class FrontDeskUpgradeTest extends TestCase {
    use RefreshDatabase;
    private int $hotel;private int $room;private User $owner;
    protected function setUp():void{parent::setUp();$this->travelTo(now()->setDate(2026,11,15)->setTime(1,0));$this->seed();$this->hotel=DB::table('hotels')->min('id');$this->room=DB::table('rooms')->where('hotel_id',$this->hotel)->where('number','204')->value('id');$this->owner=User::where('email','owner@example.test')->first();$this->actingAs($this->owner)->withSession(['hotel_id'=>$this->hotel]);}
    private function booking():int{$this->postJson('/api/reservations',['room_id'=>$this->room,'guest'=>'Tamu QA','arrival'=>'2026-11-15','departure'=>'2026-11-17','adults'=>2,'source'=>'Langsung'])->assertOk();return DB::table('reservations')->max('id');}
    private function deposit(int $id,string $type,int $amount,?string $key=null){return $this->postJson('/api/deposits/'.$id,['type'=>$type,'amount'=>$amount,'method'=>'Tunai','notes'=>'Pengujian deposit','request_key'=>$key??(string)Str::uuid()]);}
    public function test_identity_is_encrypted_private_masked_and_checkin_requires_it():void{
        Storage::fake('local');$id=$this->booking();$this->putJson('/api/reservations/'.$id,['action'=>'checkin'])->assertUnprocessable();
        $number='0000000000000000';$file=UploadedFile::fake()->image('ktp-contoh.png');
        $this->post('/api/guests/'.$id,['guest'=>'Tamu QA','identity_type'=>'KTP','identity_number'=>$number,'document'=>$file,'checkin'=>1])->assertOk();
        $p=DB::table('guest_profiles')->where('reservation_id',$id)->first();$this->assertStringNotContainsString($number,$p->details);$this->assertEquals($number,decrypt($p->details)['identity_number']);
        Storage::disk('local')->assertExists($p->document_path);$this->assertStringNotContainsString('PNG',Storage::disk('local')->get($p->document_path));
        $this->getJson('/api/data')->assertDontSee($number);$this->get('/guests/'.$id.'/document')->assertOk()->assertHeader('Cache-Control','no-store, private');
        $this->actingAs(User::where('email','housekeeping@example.test')->first())->getJson('/api/guests/'.$id)->assertForbidden();$this->get('/guests/'.$id.'/document')->assertForbidden();
    }
    public function test_identity_checkin_rolls_back_when_room_is_dirty():void{
        $id=$this->booking();DB::table('rooms')->where('id',$this->room)->update(['housekeeping'=>'dirty']);
        $this->postJson('/api/guests/'.$id,['guest'=>'Tamu QA','identity_type'=>'Paspor','identity_number'=>'TEST-IDENTITY','checkin'=>true])->assertUnprocessable();$this->assertDatabaseMissing('guest_profiles',['reservation_id'=>$id]);$this->assertDatabaseHas('reservations',['id'=>$id,'status'=>'confirmed']);
    }
    public function test_deposit_receive_apply_refund_and_balanced_ledger_are_idempotent():void{
        $id=$this->booking();$key=(string)Str::uuid();$this->deposit($id,'receive',300000,$key)->assertOk();$this->deposit($id,'receive',300000,$key)->assertOk();$this->assertSame(1,DB::table('deposit_entries')->count());
        $this->deposit($id,'apply',200000)->assertOk();$this->deposit($id,'refund',100001)->assertUnprocessable();$this->deposit($id,'refund',100000)->assertOk();
        $this->assertDatabaseHas('payments',['reservation_id'=>$id,'method'=>'Deposit','amount'=>200000]);$this->assertEquals(DB::table('journal_lines')->sum('debit'),DB::table('journal_lines')->sum('credit'));
        $this->getJson('/api/ledger?from=2026-11-15&to=2026-11-15')->assertOk();$liability=DB::table('ledger_accounts')->where('code','2000')->value('id');$this->assertEquals(0,DB::table('journal_lines')->where('account_id',$liability)->sum('debit')-DB::table('journal_lines')->where('account_id',$liability)->sum('credit'));
    }
    public function test_refund_reduces_paid_balance_and_allows_cancellation_after_return():void{
        $id=$this->booking();$this->putJson('/api/payments/'.$id,['amount'=>100000,'method'=>'Tunai'])->assertOk();
        $p=['amount'=>100000,'method'=>'Tunai','notes'=>'Pembatalan pengujian','request_key'=>(string)Str::uuid()];$this->postJson('/api/refunds/'.$id,$p)->assertOk();$this->postJson('/api/refunds/'.$id,$p)->assertOk();$this->assertSame(1,DB::table('payment_refunds')->count());$this->putJson('/api/reservations/'.$id,['action'=>'cancel'])->assertOk();
    }
    public function test_change_room_and_extension_keep_rate_and_reject_overlap():void{
        $id=$this->booking();$this->postJson('/api/guests/'.$id,['guest'=>'Tamu QA','identity_type'=>'Paspor','identity_number'=>'TEST-IDENTITY','checkin'=>true])->assertOk();
        $target=DB::table('rooms')->where('hotel_id',$this->hotel)->where('number','208')->value('id');$before=DB::table('reservations')->find($id);
        $this->putJson('/api/stays/'.$id,['room_id'=>$target,'departure'=>'2026-11-18','notes'=>'Pindah contoh'])->assertOk();$this->assertDatabaseHas('rooms',['id'=>$this->room,'housekeeping'=>'dirty']);$this->assertDatabaseHas('reservations',['id'=>$id,'room_id'=>$target,'total'=>$before->nightly_rate*3]);
        $blocked=DB::table('rooms')->where('hotel_id',$this->hotel)->where('number','207')->value('id');$this->putJson('/api/stays/'.$id,['room_id'=>$blocked,'departure'=>'2026-11-19','notes'=>'Bentrok'])->assertUnprocessable();
    }
    public function test_public_booking_quotes_server_prices_and_preserves_tenant_boundaries():void{
        DB::table('hotels')->where('id',$this->hotel)->update(['public_booking'=>true,'public_slug'=>'hotel-qa']);$room=DB::table('rooms')->find($this->room);
        $this->get('/booking/hotel-qa?arrival=2026-11-15&departure=2026-11-17&adults=2')->assertOk()->assertDontSee('Tamu Contoh 1');
        $p=['arrival'=>'2026-11-15','departure'=>'2026-11-17','adults'=>2,'room_id'=>$this->room,'guest'=>'Tamu Publik Contoh','phone'=>'0000000000','email'=>'guest@example.test','quoted_rate'=>$room->rate,'request_key'=>(string)Str::uuid()];
        $this->postJson('/booking/hotel-qa',[...$p,'quoted_rate'=>1])->assertUnprocessable();$response=$this->post('/booking/hotel-qa',$p)->assertRedirect();$this->get($response->headers->get('Location'))->assertOk()->assertSee('Reservasi Anda diterima');$this->post('/booking/hotel-qa',$p)->assertRedirect();$this->assertSame(1,DB::table('reservations')->where('source','Website')->count());
        $this->postJson('/booking/hotel-qa',[...$p,'request_key'=>(string)Str::uuid()])->assertUnprocessable();$this->get('/booking/tidak-aktif')->assertNotFound();$this->postJson('/booking/hotel-qa',[...$p,'arrival'=>'invalid'])->assertUnprocessable();
    }
    public function test_roster_leave_and_manual_attendance_corrections():void{
        $emp=DB::table('employees')->where('hotel_id',$this->hotel)->value('id');$this->getJson('/api/workforce?date=2026-11-15')->assertOk()->assertJsonCount(3,'employees');
        $this->postJson('/api/leaves',['employee_id'=>$emp,'starts_on'=>'2026-11-15','ends_on'=>'2026-11-16','type'=>'cuti','reason'=>'Contoh izin'])->assertOk();$id=DB::table('leave_requests')->max('id');$this->putJson('/api/leaves/'.$id,['status'=>'approved'])->assertOk();
        $this->postJson('/api/attendance-correction',['employee_id'=>$emp,'date'=>'2026-11-15','status'=>'leave','notes'=>'Koreksi contoh'])->assertOk();$this->assertDatabaseHas('attendances',['employee_id'=>$emp,'status'=>'leave','clock_in'=>null]);
        $this->actingAs(User::where('email','housekeeping@example.test')->first())->getJson('/api/workforce?date=2026-11-15')->assertForbidden();
    }
    public function test_linen_cycle_and_amenities_cannot_have_negative_stock():void{
        $item=DB::table('inventory')->where('hotel_id',$this->hotel)->first();$this->putJson('/api/stock/'.$item->id.'/kind',['kind'=>'linen'])->assertOk();
        foreach(['soil','send_laundry','receive_laundry']as$type)$this->postJson('/api/stock/'.$item->id,['type'=>$type,'quantity'=>2,'room_id'=>$this->room,'notes'=>'Contoh mutasi'])->assertOk();
        $this->assertDatabaseHas('inventory',['id'=>$item->id,'quantity'=>$item->quantity,'dirty_quantity'=>0,'laundry_quantity'=>0]);$this->postJson('/api/stock/'.$item->id,['type'=>'consume','quantity'=>$item->quantity+1,'notes'=>'Tolak stok negatif'])->assertUnprocessable();
    }
    public function test_password_change_checks_current_password_and_revokes_other_sessions():void{
        $this->postJson('/api/account/password',['current_password'=>'wrong','password'=>'NewPassword123!','password_confirmation'=>'NewPassword123!'])->assertUnprocessable();
        DB::table('sessions')->insert(['id'=>'other-session','user_id'=>$this->owner->id,'payload'=>'test','last_activity'=>time()]);
        $this->postJson('/api/account/password',['current_password'=>'DemoHotel2026!','password'=>'NewPassword123!','password_confirmation'=>'NewPassword123!'])->assertOk();$this->assertTrue(Hash::check('NewPassword123!',User::find($this->owner->id)->password));$this->assertDatabaseMissing('sessions',['id'=>'other-session']);
        $this->postJson('/api/account/profile',['name'=>'Owner Diperbarui','email'=>'owner-new@example.test','current_password'=>'NewPassword123!'])->assertOk();
    }
    public function test_ledger_rebuild_is_balanced_and_does_not_duplicate_history():void{
        $this->postJson('/api/ledger-rebuild',[])->assertOk();$count=DB::table('journal_entries')->count();$this->postJson('/api/ledger-rebuild',[])->assertOk();$this->assertSame($count,DB::table('journal_entries')->count());$this->assertEquals(DB::table('journal_lines')->sum('debit'),DB::table('journal_lines')->sum('credit'));$this->getJson('/api/ledger?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(6,'accounts');
    }

    public function test_leave_record_cannot_be_closed_as_an_open_clockin():void{
        $employee=DB::table('employees')->where('hotel_id',$this->hotel)->value('id');
        $this->postJson('/api/attendance-correction',['employee_id'=>$employee,'date'=>'2026-11-14','status'=>'leave','notes'=>'Contoh cuti'])->assertOk();
        $this->postJson('/api/attendances',['employee_id'=>$employee,'action'=>'clockout'])->assertUnprocessable();
        $this->postJson('/api/attendances',['employee_id'=>$employee,'action'=>'clockin'])->assertOk();
        $this->assertDatabaseHas('attendances',['employee_id'=>$employee,'date'=>'2026-11-14','clock_out'=>null]);
    }
    public function test_frontdesk_ids_are_scoped_and_held_deposits_block_departure():void{
        $room=DB::table('rooms')->where('hotel_id','!=',$this->hotel)->first();
        $foreign=DB::table('reservations')->insertGetId(['hotel_id'=>$room->hotel_id,'room_id'=>$room->id,'code'=>'FOREIGN-QA','guest'=>'Contoh hotel lain','arrival'=>'2026-11-15','departure'=>'2026-11-16','source'=>'Langsung','total'=>$room->rate,'created_at'=>now(),'updated_at'=>now()]);
        $this->getJson('/api/reservation-detail/'.$foreign)->assertNotFound();
        $this->getJson('/api/guests/'.$foreign)->assertNotFound();
        $this->deposit($foreign,'receive',100)->assertNotFound();
        $id=$this->booking();$this->postJson('/api/guests/'.$id,['guest'=>'Tamu QA','identity_type'=>'Paspor','identity_number'=>'TEST-IDENTITY','checkin'=>true])->assertOk();
        $amount=DB::table('reservations')->where('id',$id)->value('total');$this->putJson('/api/payments/'.$id,['amount'=>$amount,'method'=>'Tunai'])->assertOk();
        $this->deposit($id,'receive',100000)->assertOk();$this->putJson('/api/reservations/'.$id,['action'=>'checkout'])->assertUnprocessable();
        $this->deposit($id,'refund',100000)->assertOk();$this->putJson('/api/reservations/'.$id,['action'=>'checkout'])->assertOk();
    }
    public function test_spa_outlet_menu_scope_and_room_bill():void{
        $id=$this->booking();$this->postJson('/api/guests/'.$id,['guest'=>'Tamu QA','identity_type'=>'Paspor','identity_number'=>'TEST-IDENTITY','checkin'=>true])->assertOk();
        $this->postJson('/api/food_items',['name'=>'Spa Contoh','category'=>'Perawatan','price'=>100000,'outlet'=>'spa'])->assertOk();
        $item=DB::table('food_items')->where('name','Spa Contoh')->value('id');$before=DB::table('reservations')->where('id',$id)->value('total');
        $p=['outlet'=>'spa','order_type'=>'room_service','reservation_id'=>$id,'items'=>[['food_item_id'=>$item,'quantity'=>2]],'request_key'=>(string)Str::uuid()];
        $this->postJson('/api/food_orders',[...$p,'outlet'=>'restaurant'])->assertUnprocessable();
        $this->postJson('/api/food_orders',$p)->assertOk();$order=DB::table('food_orders')->latest('id')->first();
        $this->assertEquals('spa',$order->outlet);$this->assertEquals($before+$order->total,DB::table('reservations')->where('id',$id)->value('total'));
        $this->get('/restaurant/'.$order->id.'/receipt')->assertOk()->assertSee('Spa');
    }
}
