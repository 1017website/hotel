<?php
namespace App\Http\Controllers;
use App\Support\HotelContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class StockController extends Controller {
    public function move(Request $r,int $id){
        $c=new HotelContext($r);$c->authorize('inventory.manage');$d=$r->validate(['type'=>['required',Rule::in(['consume','soil','send_laundry','receive_laundry','restock'])],'quantity'=>'required|integer|min:1|max:1000000','room_id'=>['nullable','integer',Rule::exists('rooms','id')->where('hotel_id',$c->hotel->id)],'notes'=>'required|string|max:500','request_key'=>'nullable|uuid']);
        DB::transaction(function()use($c,$d,$id){$i=$c->table('inventory')->where('id',$id)->lockForUpdate()->first();abort_unless($i,404);
            if(!empty($d['request_key'])&&($old=$c->table('stock_movements')->where('request_key',$d['request_key'])->first())){if($old->inventory_id!==$id)throw ValidationException::withMessages(['request_key'=>'Referensi sudah dipakai pada barang lain.']);return;}
            if(in_array($d['type'],['soil','send_laundry','receive_laundry'])&&$i->kind!=='linen')throw ValidationException::withMessages(['type'=>'Alur laundry hanya untuk barang jenis linen.']);
            $from=match($d['type']){'send_laundry'=>'dirty_quantity','receive_laundry'=>'laundry_quantity','consume','soil'=>'quantity',default=>null};$to=match($d['type']){'restock','receive_laundry'=>'quantity','soil'=>'dirty_quantity','send_laundry'=>'laundry_quantity',default=>null};
            if($from&&$i->{$from}<$d['quantity'])throw ValidationException::withMessages(['quantity'=>'Jumlah melebihi stok yang tersedia.']);
            if($to&&$i->{$to}+$d['quantity']>1000000)throw ValidationException::withMessages(['quantity'=>'Stok tujuan melebihi batas 1.000.000 unit.']);
            if($from)$c->table('inventory')->where('id',$id)->decrement($from,$d['quantity']);if($to)$c->table('inventory')->where('id',$id)->increment($to,$d['quantity']);
            $move=DB::table('stock_movements')->insertGetId([...$d,'inventory_id'=>$id,'hotel_id'=>$c->hotel->id,'user_id'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]);$c->audit('Mutasi perlengkapan','stock_movements',$move);});return response()->json(['message'=>'Mutasi stok dicatat.']);
    }
    public function kind(Request $r,int $id){$c=new HotelContext($r);$c->authorize('inventory.manage');$d=$r->validate(['kind'=>['required',Rule::in(['linen','amenity'])]]);DB::transaction(function()use($c,$d,$id){$i=$c->table('inventory')->where('id',$id)->lockForUpdate()->first();abort_unless($i,404);if($d['kind']==='amenity'&&($i->dirty_quantity||$i->laundry_quantity))throw ValidationException::withMessages(['kind'=>'Selesaikan alur linen sebelum mengganti jenis barang.']);$c->table('inventory')->where('id',$id)->update($d);$c->audit('Jenis perlengkapan diubah','inventory',$id);});return response()->json(['message'=>'Jenis barang disimpan.']);}
}
