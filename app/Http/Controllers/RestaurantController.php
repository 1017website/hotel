<?php
namespace App\Http\Controllers;

use App\Support\HotelContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RestaurantController extends Controller {
    public function receipt(Request $r,int $id) {
        $c=new HotelContext($r); $c->authorize('food.view');
        $order=$c->table('food_orders')->find($id); abort_unless($order,404);
        $direct=$order->outlet!=='restaurant'&&$order->order_type==='takeaway';
        $order->outlet_label=['restaurant'=>'Restoran / kafe','spa'=>'Spa','facility'=>'Fasilitas'][$order->outlet];
        $order->order_type=$direct?'Layanan langsung':['room_service'=>'Layanan kamar','dine_in'=>'Makan di tempat','takeaway'=>'Takeaway'][$order->order_type];
        return response()->view('restaurant-receipt',['hotel'=>$c->hotel,'order'=>$order])->header('Cache-Control','no-store');
    }
    private function invalid(string $message): never { throw ValidationException::withMessages(['order'=>$message]); }
    public function store(Request $r) {
        $c = new HotelContext($r); $c->authorize('food.manage');
        if (!$r->has('items') && $r->has('food_item_id')) $r->merge(['items'=>[['food_item_id'=>$r->input('food_item_id'),'quantity'=>$r->input('quantity')]],'order_type'=>'room_service']);
        $d = $r->validate([
            'items'=>'required|array|min:1|max:100','items.*.food_item_id'=>['required','integer','distinct',Rule::exists('food_items','id')->where('hotel_id',$c->hotel->id)],
            'items.*.quantity'=>'required|integer|min:1|max:100','order_type'=>['required',Rule::in(['room_service','dine_in','takeaway'])],
            'reservation_id'=>['required_if:order_type,room_service','nullable','integer',Rule::exists('reservations','id')->where('hotel_id',$c->hotel->id)],
            'table_label'=>'required_if:order_type,dine_in|nullable|string|max:40','customer'=>'nullable|string|max:100',
            'discount'=>'nullable|integer|min:0|max:100000000','notes'=>'nullable|string|max:1000','request_key'=>'nullable|uuid',
            'outlet'=>['nullable',Rule::in(['restaurant','spa','facility'])],
        ]);
        return DB::transaction(function () use ($c,$d) {
            DB::table('hotels')->where('id',$c->hotel->id)->lockForUpdate()->first();
            if (!empty($d['request_key']) && ($old=$c->table('food_orders')->where('request_key',$d['request_key'])->first())) return response()->json(['message'=>'Pesanan sudah tersimpan.','id'=>$old->id]);
            $booking = null;
            if ($d['order_type']==='room_service') {
                $booking=$c->table('reservations')->where('id',$d['reservation_id'])->lockForUpdate()->first();
                if ($booking->status!=='checked_in') $this->invalid('Tagihan kamar hanya untuk tamu yang sedang menginap.');
            }
            $menu=$c->table('food_items')->whereIn('id',array_column($d['items'],'food_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines=[]; $subtotal=0;
            foreach ($d['items'] as $line) {
                $item=$menu[$line['food_item_id']];
                if($item->outlet!==($d['outlet']??'restaurant'))$this->invalid('Menu tidak berasal dari outlet yang dipilih.');
                if (!$item->available) $this->invalid('Menu '.$item->name.' sedang habis.');
                $lines[]=['food_item_id'=>$item->id,'name'=>$item->name,'quantity'=>(int)$line['quantity'],'price'=>(int)$item->price];
                $subtotal+=$item->price*$line['quantity'];
            }
            $discount=(int)($d['discount']??0);
            if ($discount>$subtotal) $this->invalid('Diskon melebihi subtotal.');
            if ($discount>0) $c->authorize('payments.manage');
            $net=$subtotal-$discount;
            $service=(int)round($net*$c->hotel->restaurant_service/100);
            $tax=(int)round(($net+$service)*$c->hotel->restaurant_tax/100);
            $total=$net+$service+$tax;
            $id=DB::table('food_orders')->insertGetId([
                'hotel_id'=>$c->hotel->id,'reservation_id'=>$booking?->id,'order_type'=>$d['order_type'],
                'table_label'=>$d['table_label']??null,'customer'=>$d['customer']??null,'items'=>json_encode($lines),
                'subtotal'=>$subtotal,'discount'=>$discount,'tax'=>$tax,'service'=>$service,'total'=>$total,
                'outlet'=>$d['outlet']??'restaurant',
                'payment_status'=>$booking?'room':'unpaid','request_key'=>$d['request_key']??null,'notes'=>$d['notes']??null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            if ($booking) $c->table('reservations')->where('id',$booking->id)->increment('total',$total,['updated_at'=>now()]);
            $c->audit('Pesanan restoran dibuat','food_orders',$id);
            return response()->json(['message'=>'Pesanan dikirim ke dapur.','id'=>$id]);
        },3);
    }
    public function pay(Request $r,int $id) {
        $c=new HotelContext($r); $c->authorize('payments.manage');
        $d=$r->validate(['method'=>['required',Rule::in(['Tunai','Kartu','Transfer'])],'tendered'=>'required|integer|min:0|max:1000000000']);
        return DB::transaction(function () use($c,$d,$id) {
            $o=$c->table('food_orders')->where('id',$id)->lockForUpdate()->first(); abort_unless($o,404);
            if ($o->payment_status==='paid') return response()->json(['message'=>'Pesanan sudah dibayar.']);
            if ($o->payment_status==='room') $this->invalid('Pesanan ini ditagihkan lewat reservasi kamar.');
            if ($d['tendered']<$o->total) $this->invalid('Nominal pembayaran belum mencukupi.');
            $c->table('food_orders')->where('id',$id)->update(['payment_status'=>'paid','payment_method'=>$d['method'],'tendered'=>$d['method']==='Tunai'?$d['tendered']:$o->total,'paid_at'=>now(),'updated_at'=>now()]);
            $c->audit('Pembayaran POS diterima','food_orders',$id,['amount'=>$o->total]);
            \App\Services\LedgerService::source('food_orders',$id);
            return response()->json(['message'=>'Pembayaran tercatat.','change'=>$d['method']==='Tunai'?$d['tendered']-$o->total:0]);
        },3);
    }
    public function breakfast(Request $r,int $id) {
        $c=new HotelContext($r); $c->authorize('reservations.manage');
        $d=$r->validate(['guests'=>'required|integer|min:0|max:20']);
        return DB::transaction(function () use($c,$d,$id) {
            $b=$c->table('reservations')->where('id',$id)->lockForUpdate()->first(); abort_unless($b,404);
            $max=$c->table('breakfast_visits')->where('reservation_id',$id)->selectRaw('SUM(quantity) as used')->groupBy('service_date')->get()->max('used')??0;
            if ($d['guests']>$b->adults || $d['guests']<$max) $this->invalid('Jatah harus sesuai jumlah tamu dan tidak kurang dari porsi yang sudah digunakan.');
            $c->table('reservations')->where('id',$id)->update(['breakfast_guests'=>$d['guests'],'updated_at'=>now()]);
            $c->audit('Jatah breakfast diperbarui','reservations',$id);
            return response()->json(['message'=>'Jatah breakfast per hari disimpan.']);
        });
    }
    public function redeem(Request $r) {
        $c=new HotelContext($r); $c->authorize('food.manage');
        $d=$r->validate(['reservation_id'=>['required','integer',Rule::exists('reservations','id')->where('hotel_id',$c->hotel->id)],'quantity'=>'required|integer|min:1|max:20','request_key'=>'required|uuid','notes'=>'nullable|string|max:500']);
        return DB::transaction(function () use($c,$d) {
            $b=$c->table('reservations')->where('id',$d['reservation_id'])->lockForUpdate()->first();
            if ($c->table('breakfast_visits')->where('request_key',$d['request_key'])->exists()) return response()->json(['message'=>'Kunjungan sudah tercatat.']);
            $today=now($c->hotel->timezone)->toDateString();
            $leftToday=$b->checked_out_at && Carbon::parse($b->checked_out_at,'UTC')->timezone($c->hotel->timezone)->toDateString()===$today;
            if (!in_array($b->status,['checked_in','checked_out']) || $b->arrival>=$today || $b->departure<$today || ($b->status==='checked_out' && !$leftToday)) $this->invalid('Reservasi tidak memiliki hak breakfast hari ini. Hak dimulai pagi setelah tanggal kedatangan.');
            $used=$c->table('breakfast_visits')->where('reservation_id',$b->id)->where('service_date',$today)->sum('quantity');
            if ($used+$d['quantity']>$b->breakfast_guests) $this->invalid('Porsi melebihi sisa jatah breakfast hari ini.');
            $time=now($c->hotel->timezone)->format('H:i:s');
            if ($time<Carbon::parse($c->hotel->breakfast_start)->format('H:i:s') || $time>Carbon::parse($c->hotel->breakfast_end)->format('H:i:s')) {
                $c->authorize('reservations.manage');
                if (empty($d['notes'])) $this->invalid('Di luar jam breakfast. Petugas reservasi wajib mengisi alasan pengecualian.');
            }
            $id=DB::table('breakfast_visits')->insertGetId([...$d,'hotel_id'=>$c->hotel->id,'service_date'=>$today,'user_id'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]);
            $c->audit('Breakfast digunakan','breakfast_visits',$id,['quantity'=>$d['quantity']]);
            return response()->json(['message'=>'Kehadiran breakfast tercatat.']);
        },3);
    }
    public function settings(Request $r) {
        $c=new HotelContext($r); $c->authorize('hotel.manage');
        $d=$r->validate(['restaurant_tax'=>'required|integer|min:0|max:30','restaurant_service'=>'required|integer|min:0|max:30','breakfast_start'=>'required|date_format:H:i','breakfast_end'=>'required|date_format:H:i|after:breakfast_start']);
        DB::transaction(function () use($c,$d) { DB::table('hotels')->where('id',$c->hotel->id)->update($d); $c->audit('Pengaturan restoran diperbarui','hotels',$c->hotel->id); });
        return response()->json(['message'=>'Pengaturan restoran disimpan.']);
    }
}
