<?php
namespace App\Http\Controllers;
use App\Support\HotelContext;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class FrontDeskController extends Controller {
    private function invalid($message):never{throw ValidationException::withMessages(['data'=>$message]);}
    public function detail(Request $r,int $id){$c=new HotelContext($r);$c->authorize('reservations.view');$b=$c->table('reservations')->find($id);abort_unless($b,404);$profile=$c->table('guest_profiles')->where('reservation_id',$id)->first();$b->has_identity=(bool)$profile;$b->identity_masked=$profile?'•••• '.$profile->identity_last4:null;if($c->can('finance.view')||$c->can('payments.manage'))$b->paid=(int)$c->table('payments')->where('reservation_id',$id)->sum('amount')-(int)$c->table('payment_refunds')->where('reservation_id',$id)->sum('amount');else unset($b->total,$b->room_total,$b->nightly_rate);return response()->json(['reservation'=>$b])->header('Cache-Control','no-store');}
    public function guest(Request $r,int $id){
        $c=new HotelContext($r);$c->authorize('guests.identity');$b=$c->table('reservations')->find($id);abort_unless($b,404);$p=$c->table('guest_profiles')->where('reservation_id',$id)->first();
        if(!$p)return response()->json(['guest'=>$b->guest,'phone'=>$b->phone]);
        $c->audit('Identitas tamu dibaca','guest_profiles',$p->id);return response()->json([...decrypt($p->details),'has_document'=>(bool)$p->document_path])->header('Cache-Control','no-store');
    }
    public function saveGuest(Request $r,int $id){
        $c=new HotelContext($r);$c->authorize('reservations.manage');
        $d=$r->validate(['guest'=>'required|string|max:120','phone'=>'nullable|string|max:30','email'=>'nullable|email|max:200','identity_type'=>['required',Rule::in(['KTP','Paspor','SIM','Lainnya'])],'identity_number'=>'required|string|min:6|max:40','address'=>'nullable|string|max:500','birth_date'=>'nullable|date_format:Y-m-d|before_or_equal:'.now($c->hotel->timezone)->toDateString(),'nationality'=>'nullable|string|max:80','document'=>'nullable|file|mimes:jpg,jpeg,png,pdf|max:3072','checkin'=>'nullable|boolean']);
        if($d['identity_type']==='KTP'&&!preg_match('/^\d{16}$/',$d['identity_number']))$this->invalid('Nomor KTP harus terdiri dari 16 angka.');
        $path=null;$oldPath=null;
        try{
            DB::transaction(function()use($r,$c,$d,$id,&$path,&$oldPath){
                $b=$c->table('reservations')->find($id);abort_unless($b,404);
                // Room precedes reservation in every front-desk transaction.
                $c->table('rooms')->where('id',$b->room_id)->lockForUpdate()->first();$locked=$c->table('reservations')->where('id',$id)->lockForUpdate()->first();
                if($locked->room_id!==$b->room_id)$this->invalid('Kamar berubah oleh petugas lain. Muat ulang terlebih dahulu.');
                $old=$c->table('guest_profiles')->where('reservation_id',$id)->first();
                $details=collect($d)->except(['document','checkin'])->all();
                $row=['details'=>encrypt($details),'identity_last4'=>substr($d['identity_number'],-4),'updated_at'=>now()];
                if($r->hasFile('document')){$path='guest-identities/'.Str::uuid().'.enc';if(!Storage::disk('local')->put($path,encrypt(base64_encode(file_get_contents($r->file('document')->getRealPath())))))throw new \RuntimeException('Dokumen identitas gagal disimpan.');$row+=['document_path'=>$path,'document_mime'=>$r->file('document')->getMimeType()];$oldPath=$old?->document_path;}
                if($old)$c->table('guest_profiles')->where('id',$old->id)->update($row);else DB::table('guest_profiles')->insert([...$row,'hotel_id'=>$c->hotel->id,'reservation_id'=>$id,'created_at'=>now()]);
                $c->table('reservations')->where('id',$id)->update(['guest'=>$d['guest'],'phone'=>$d['phone']??null,'updated_at'=>now()]);
                $c->audit('Data check-in tamu disimpan','reservations',$id);
                if($r->boolean('checkin'))(new ReservationService)->transition($c,$id,'checkin');
            });
        }catch(\Throwable $e){if($path)Storage::disk('local')->delete($path);throw $e;}
        if($oldPath)Storage::disk('local')->delete($oldPath);
        return response()->json(['message'=>$r->boolean('checkin')?'Data tamu tersimpan dan check-in berhasil.':'Data tamu disimpan terenkripsi.']);
    }
    public function document(Request $r,int $id){
        $c=new HotelContext($r);$c->authorize('guests.identity');$p=$c->table('guest_profiles')->where('reservation_id',$id)->first();abort_unless($p&&$p->document_path,404);$c->audit('Dokumen identitas dibuka','guest_profiles',$p->id);
        return response(base64_decode(decrypt(Storage::disk('local')->get($p->document_path))))->header('Content-Type',$p->document_mime)->header('Content-Disposition','attachment; filename="identitas-tamu.'.($p->document_mime==='application/pdf'?'pdf':($p->document_mime==='image/png'?'png':'jpg')).'"')->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');
    }
    public function deposit(Request $r,int $id){
        $c=new HotelContext($r);$c->authorize('payments.manage');
        $d=$r->validate(['type'=>['required',Rule::in(['receive','refund','apply'])],'amount'=>'required|integer|min:1|max:1000000000','method'=>['required',Rule::in(['Tunai','Transfer','Kartu'])],'notes'=>'required|string|max:500','request_key'=>'required|uuid']);
        return DB::transaction(function()use($c,$d,$id){
            $b=$c->table('reservations')->where('id',$id)->lockForUpdate()->first();abort_unless($b,404);
            if($c->table('deposit_entries')->where('request_key',$d['request_key'])->exists())return response()->json(['message'=>'Transaksi deposit sudah tercatat.']);
            $balance=$c->table('deposit_entries')->where('reservation_id',$id)->selectRaw("COALESCE(SUM(CASE WHEN type='receive' THEN amount ELSE -amount END),0) as balance")->value('balance');
            if($d['type']==='receive'&&!in_array($b->status,['confirmed','checked_in']))$this->invalid('Deposit baru hanya untuk reservasi aktif.');
            if($d['type']!=='receive'&&$d['amount']>$balance)$this->invalid('Nominal melebihi saldo deposit yang ditahan.');
            if($d['type']==='apply'){
                $paid=$c->table('payments')->where('reservation_id',$id)->sum('amount')-$c->table('payment_refunds')->where('reservation_id',$id)->sum('amount');
                if(!in_array($b->status,['confirmed','checked_in'])||$d['amount']>$b->total-$paid)$this->invalid('Deposit yang digunakan melebihi sisa tagihan atau reservasi sudah ditutup.');
                $payment=DB::table('payments')->insertGetId(['hotel_id'=>$c->hotel->id,'reservation_id'=>$id,'amount'=>$d['amount'],'method'=>'Deposit','user_id'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]);\App\Services\LedgerService::source('payments',$payment);
            }
            $entry=DB::table('deposit_entries')->insertGetId([...$d,'hotel_id'=>$c->hotel->id,'reservation_id'=>$id,'user_id'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]);
            \App\Services\LedgerService::source('deposit_entries',$entry);$c->audit('Deposit '.$d['type'],'deposit_entries',$entry,['amount'=>$d['amount']]);return response()->json(['message'=>'Transaksi deposit tersimpan.']);
        },3);
    }
    public function changeStay(Request $r,int $id){
        $c=new HotelContext($r);$c->authorize('reservations.manage');$d=$r->validate(['room_id'=>['required','integer',Rule::exists('rooms','id')->where('hotel_id',$c->hotel->id)],'departure'=>'required|date_format:Y-m-d','notes'=>'required|string|max:500']);
        return DB::transaction(function()use($c,$d,$id){
            $initial=$c->table('reservations')->find($id);abort_unless($initial,404);
            $locked=$c->table('rooms')->whereIn('id',[$initial->room_id,$d['room_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $b=$c->table('reservations')->where('id',$id)->lockForUpdate()->first();
            if($b->room_id!==$initial->room_id)$this->invalid('Kamar berubah oleh petugas lain. Muat ulang terlebih dahulu.');
            $target=$locked[$d['room_id']];$moved=$b->room_id!=$target->id;
            if(!in_array($b->status,['confirmed','checked_in'])||$d['departure']<$b->departure)$this->invalid('Perubahan hanya untuk reservasi aktif, tanpa memendekkan masa menginap.');
            if($target->out_of_service||$b->adults>$target->capacity||($moved&&$b->status==='checked_in'&&$target->housekeeping!=='clean'))$this->invalid('Kamar tujuan belum siap atau kapasitas tidak mencukupi.');
            if($c->table('reservations')->where('room_id',$target->id)->where('id','!=',$id)->whereIn('status',['confirmed','checked_in'])->where('arrival','<',$d['departure'])->where('departure','>',$b->arrival)->exists())$this->invalid('Kamar tujuan bertabrakan dengan reservasi lain.');
            if($moved&&$b->status==='checked_in'&&$c->table('reservations')->where('room_id',$target->id)->where('status','checked_in')->exists())$this->invalid('Kamar tujuan masih ditempati.');
            $nights=(int)\Carbon\Carbon::parse($b->arrival)->diffInDays($d['departure']);$roomTotal=$nights*$b->nightly_rate;
            $c->table('reservations')->where('id',$id)->update(['room_id'=>$target->id,'departure'=>$d['departure'],'room_total'=>$roomTotal,'total'=>$b->total-$b->room_total+$roomTotal,'updated_at'=>now()]);
            if($moved&&$b->status==='checked_in')$c->table('rooms')->where('id',$b->room_id)->update(['housekeeping'=>'dirty','updated_at'=>now()]);
            $c->audit('Kamar / durasi menginap diubah','reservations',$id,['from_room'=>$b->room_id,'to_room'=>$target->id,'departure'=>$d['departure'],'reason'=>$d['notes']]);return response()->json(['message'=>'Kamar dan masa menginap diperbarui. Tarif per malam reservasi dipertahankan.']);
        },3);
    }
}
