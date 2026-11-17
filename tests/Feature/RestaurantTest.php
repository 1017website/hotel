<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class RestaurantTest extends TestCase {
    use RefreshDatabase;
    private int $hotel;
    protected function setUp():void {
        parent::setUp(); $this->travelTo(now()->setDate(2026,11,15)->setTime(1,0)); $this->seed();
        $this->hotel=DB::table('hotels')->min('id');
        $this->actingAs(User::where('email','owner@example.test')->first())->withSession(['hotel_id'=>$this->hotel]);
    }
    private function payload():array {
        $items=DB::table('food_items')->where('hotel_id',$this->hotel)->take(2)->get();
        return ['order_type'=>'dine_in','table_label'=>'Meja 08','request_key'=>(string)Str::uuid(),'items'=>[['food_item_id'=>$items[0]->id,'quantity'=>2],['food_item_id'=>$items[1]->id,'quantity'=>3]]];
    }
    public function test_multi_menu_prices_discount_tax_payment_and_receipt():void {
        DB::table('hotels')->where('id',$this->hotel)->update(['restaurant_tax'=>10,'restaurant_service'=>5]);
        $p=$this->payload()+['discount'=>1000,'total'=>1];
        $res=$this->postJson('/api/food_orders',$p)->assertOk(); $id=$res->json('id');
        $o=DB::table('food_orders')->find($id); $this->assertCount(2,json_decode($o->items,true));
        $base=0; foreach($p['items'] as $i)$base+=DB::table('food_items')->where('id',$i['food_item_id'])->value('price')*$i['quantity'];
        $net=$base-1000; $service=(int)round($net*.05); $tax=(int)round(($net+$service)*.10);
        $this->assertEquals($net+$service+$tax,$o->total);
        $this->postJson('/api/food_orders',$p)->assertOk()->assertJsonPath('id',$id);
        $this->assertSame(1,DB::table('food_orders')->count());
        $this->putJson('/api/restaurant-pay/'.$id,['method'=>'Tunai','tendered'=>$o->total-1])->assertUnprocessable();
        $this->putJson('/api/restaurant-pay/'.$id,['method'=>'Tunai','tendered'=>$o->total+5000])->assertOk()->assertJsonPath('change',5000);
        $this->putJson('/api/restaurant-pay/'.$id,['method'=>'Tunai','tendered'=>$o->total+5000])->assertOk();
        $this->getJson('/api/data')->assertJsonPath('finance.restaurant_income',(int)$o->total);
        $this->get('/restaurant/'.$id.'/receipt')->assertOk()->assertSee('Lunas');
    }
    public function test_multi_menu_room_bill_changes_once_and_is_not_paid_twice():void {
        $b=DB::table('reservations')->where('status','checked_in')->first(); $p=$this->payload();
        $p['order_type']='room_service'; $p['reservation_id']=$b->id;
        $id=$this->postJson('/api/food_orders',$p)->assertOk()->json('id');
        $this->postJson('/api/food_orders',$p)->assertOk(); $o=DB::table('food_orders')->find($id);
        $this->assertDatabaseHas('reservations',['id'=>$b->id,'total'=>$b->total+$o->total]);
        $this->putJson('/api/restaurant-pay/'.$id,['method'=>'Tunai','tendered'=>$o->total])->assertUnprocessable();
    }
    public function test_foreign_menu_and_foreign_booking_are_rejected():void {
        $p=$this->payload(); $p['items'][0]['food_item_id']=DB::table('food_items')->where('hotel_id','!=',$this->hotel)->value('id');
        $this->postJson('/api/food_orders',$p)->assertUnprocessable();
        $this->postJson('/api/food_orders',[...$this->payload(),'order_type'=>'takeaway','items'=>[]])->assertUnprocessable();
        $this->actingAs(User::where('email','housekeeping@example.test')->first())->postJson('/api/food_orders',$this->payload())->assertForbidden();
    }
    public function test_breakfast_daily_quota_idempotency_and_reset_next_day():void {
        $b=DB::table('reservations')->where('status','checked_in')->first();
        $this->putJson('/api/breakfast/'.$b->id,['guests'=>2])->assertOk();
        $p=['reservation_id'=>$b->id,'quantity'=>1,'request_key'=>(string)Str::uuid()];
        $this->postJson('/api/breakfast-visits',$p)->assertOk(); $this->postJson('/api/breakfast-visits',$p)->assertOk();
        $this->assertSame(1,DB::table('breakfast_visits')->count());
        $this->postJson('/api/breakfast-visits',[...$p,'quantity'=>2,'request_key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->putJson('/api/breakfast/'.$b->id,['guests'=>0])->assertUnprocessable();
        $this->travel(1)->days();
        $this->postJson('/api/breakfast-visits',[...$p,'quantity'=>2,'request_key'=>(string)Str::uuid()])->assertOk();
        $this->assertEquals(3,DB::table('breakfast_visits')->sum('quantity'));
    }
    public function test_breakfast_outside_hours_needs_authorized_reason():void {
        $b=DB::table('reservations')->where('status','checked_in')->first();
        $this->putJson('/api/breakfast/'.$b->id,['guests'=>2])->assertOk(); $this->travel(6)->hours();
        $p=['reservation_id'=>$b->id,'quantity'=>1,'request_key'=>(string)Str::uuid()];
        $this->postJson('/api/breakfast-visits',$p)->assertUnprocessable();
        $this->postJson('/api/breakfast-visits',$p+['notes'=>'Pengecualian operasional pengujian'])->assertOk();
    }
}
