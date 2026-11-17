<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
class LedgerService {
    public static function accounts(int $hotel):void{
        foreach([['1000','Kas & bank','asset'],['2000','Deposit tamu ditahan','liability'],['3000','Modal / penyesuaian ekuitas','equity'],['4000','Penerimaan akomodasi & tagihan kamar','income'],['4100','Penerimaan POS langsung','income'],['5000','Biaya operasional','expense']]as[$code,$name,$type])DB::table('ledger_accounts')->insertOrIgnore(['hotel_id'=>$hotel,'code'=>$code,'name'=>$name,'type'=>$type,'created_at'=>now(),'updated_at'=>now()]);
    }
    public static function post(int $hotel,string $ref,string $date,string $description,array $lines,?int $user=null):void{
        DB::transaction(function()use($hotel,$ref,$date,$description,$lines,$user){self::accounts($hotel);
            $inserted=DB::table('journal_entries')->insertOrIgnore(['hotel_id'=>$hotel,'reference'=>$ref,'date'=>$date,'description'=>$description,'user_id'=>$user,'created_at'=>now(),'updated_at'=>now()]);if(!$inserted)return;
            $id=DB::table('journal_entries')->where('hotel_id',$hotel)->where('reference',$ref)->value('id');$debits=0;$credits=0;
            foreach($lines as[$code,$debit,$credit]){$account=DB::table('ledger_accounts')->where('hotel_id',$hotel)->where('code',$code)->first();if(!$account||($debit>0&&$credit>0)||$debit<0||$credit<0)throw new \InvalidArgumentException('Baris jurnal tidak valid.');$debits+=$debit;$credits+=$credit;DB::table('journal_lines')->insert(['hotel_id'=>$hotel,'journal_entry_id'=>$id,'account_id'=>$account->id,'debit'=>$debit,'credit'=>$credit,'created_at'=>now(),'updated_at'=>now()]);}
            if($debits!==$credits||$debits<=0)throw new \InvalidArgumentException('Jurnal harus seimbang dan bernilai positif.');
        });
    }
    public static function source(string $table,int $id):void{
        $x=DB::table($table)->find($id);if(!$x)return;$h=DB::table('hotels')->find($x->hotel_id);$date=Carbon::parse($x->created_at,'UTC')->timezone($h->timezone)->toDateString();$user=$x->user_id??null;$amount=(int)($x->amount??$x->total??0);if($amount<=0)return;
        $lines=match($table){'payments'=>[[$x->method==='Deposit'?'2000':'1000',$amount,0],['4000',0,$amount]],'food_orders'=>$x->payment_status==='paid'?[['1000',$amount,0],['4100',0,$amount]]:[], 'expenses'=>$x->status==='approved'?[['5000',$amount,0],['1000',0,$amount]]:[], 'deposit_entries'=>match($x->type){'receive'=>[['1000',$amount,0],['2000',0,$amount]],'refund'=>[['2000',$amount,0],['1000',0,$amount]],default=>[]},'payment_refunds'=>[['4000',$amount,0],['1000',0,$amount]],default=>[]};
        if(!$lines)return;if($table==='expenses')$date=$x->date;if($table==='food_orders')$date=Carbon::parse($x->paid_at,'UTC')->timezone($h->timezone)->toDateString();
        self::post($x->hotel_id,$table.':'.$id,$date,['payments'=>'Pembayaran reservasi','food_orders'=>'Pembayaran POS','expenses'=>'Pengeluaran disetujui','deposit_entries'=>'Mutasi deposit','payment_refunds'=>'Refund pembayaran'][$table],$lines,$user);
    }
    public static function rebuild(int $hotel):void{DB::transaction(function()use($hotel){self::accounts($hotel);foreach(['deposit_entries','payments','food_orders','expenses','payment_refunds']as$table)foreach(DB::table($table)->where('hotel_id',$hotel)->orderBy('id')->pluck('id')as$id)self::source($table,$id);});}
}
