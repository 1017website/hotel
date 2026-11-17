<?php
namespace App\Http\Controllers;
use App\Support\HotelContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
class WorkforceController extends Controller {
    public function roster(Request $r){
        $c=new HotelContext($r);$c->authorize('employees.view');$d=$r->validate(['date'=>'required|date_format:Y-m-d']);
        return response()->json(['date'=>$d['date'],'now'=>now($c->hotel->timezone)->format('Y-m-d H:i:s'),'employees'=>$c->table('employees')->where(function($q)use($c,$d){$q->where('active',true)->orWhereIn('id',$c->table('attendances')->where(fn($q)=>$q->where('date',$d['date'])->orWhere(fn($q)=>$q->where('date','<',$d['date'])->whereNotNull('clock_in')->whereNull('clock_out')))->select('employee_id'));})->orderBy('department')->orderBy('name')->get(),
            'records'=>$c->table('attendances')->where(function($q)use($d){$q->where('date',$d['date'])->orWhere(fn($q)=>$q->where('date','<',$d['date'])->whereNull('clock_out')->whereNotNull('clock_in'));})->get(),
            'shifts'=>$c->table('shifts')->where('starts_at','<',$d['date'].' 23:59:59')->where('ends_at','>=',$d['date'].' 00:00:00')->get(),
            'leaves'=>$c->table('leave_requests')->where('starts_on','<=',$d['date'])->where('ends_on','>=',$d['date'])->get()])->header('Cache-Control','no-store');
    }
    public function correction(Request $r){
        $c=new HotelContext($r);$c->authorize('attendance.manage');$d=$r->validate(['employee_id'=>['required','integer',Rule::exists('employees','id')->where('hotel_id',$c->hotel->id)],'date'=>'required|date_format:Y-m-d|before_or_equal:'.now($c->hotel->timezone)->toDateString(),'status'=>['required',Rule::in(['present','sick','leave','absent'])],'clock_in'=>'required_if:status,present|nullable|date','clock_out'=>'nullable|date|after:clock_in','notes'=>'required|string|max:500']);
        if($d['status']==='present'&&substr($d['clock_in'],0,10)!==$d['date'])throw ValidationException::withMessages(['clock_in'=>'Tanggal masuk harus sama dengan tanggal kerja.']);
        if($d['status']==='present'){foreach(['clock_in','clock_out']as$key)if(!empty($d[$key])&&Carbon::parse($d[$key],$c->hotel->timezone)->gt(now($c->hotel->timezone)))throw ValidationException::withMessages([$key=>'Waktu absensi tidak boleh di masa depan.']);}
        DB::transaction(function()use($c,$d){$c->table('employees')->where('id',$d['employee_id'])->lockForUpdate()->first();
            if($d['status']!=='present'){$d['clock_in']=null;$d['clock_out']=null;}else{foreach(['clock_in','clock_out']as$key)$d[$key]=empty($d[$key])?null:Carbon::parse($d[$key])->format('Y-m-d H:i:s');}
            $d['source']='correction';
            $old=$c->table('attendances')->where('employee_id',$d['employee_id'])->where('date',$d['date'])->first();
            if($d['status']==='present'&&!$d['clock_out']&&$c->table('attendances')->where('employee_id',$d['employee_id'])->where('date','!=',$d['date'])->whereNotNull('clock_in')->whereNull('clock_out')->exists())throw ValidationException::withMessages(['clock_in'=>'Masih ada absensi masuk terbuka pada tanggal lain.']);
            if($old)$c->table('attendances')->where('id',$old->id)->update([...$d,'updated_at'=>now()]);else DB::table('attendances')->insert([...$d,'hotel_id'=>$c->hotel->id,'created_at'=>now(),'updated_at'=>now()]);
            $c->audit('Absensi dikoreksi','employees',$d['employee_id'],['date'=>$d['date'],'reason'=>$d['notes']]);});return response()->json(['message'=>'Koreksi absensi tersimpan.']);
    }
    public function leave(Request $r){
        $c=new HotelContext($r);$c->authorize('employees.manage');$d=$r->validate(['employee_id'=>['required','integer',Rule::exists('employees','id')->where('hotel_id',$c->hotel->id)],'starts_on'=>'required|date_format:Y-m-d','ends_on'=>'required|date_format:Y-m-d|after_or_equal:starts_on','type'=>['required',Rule::in(['cuti','izin','sakit'])],'reason'=>'required|string|max:500']);
        DB::transaction(function()use($c,$d){$c->table('employees')->where('id',$d['employee_id'])->lockForUpdate()->first();if($c->table('leave_requests')->where('employee_id',$d['employee_id'])->whereIn('status',['pending','approved'])->where('starts_on','<=',$d['ends_on'])->where('ends_on','>=',$d['starts_on'])->exists())throw ValidationException::withMessages(['date'=>'Ada pengajuan izin/cuti pada tanggal tersebut.']);$id=DB::table('leave_requests')->insertGetId([...$d,'hotel_id'=>$c->hotel->id,'created_at'=>now(),'updated_at'=>now()]);$c->audit('Izin/cuti diajukan','leave_requests',$id);});return response()->json(['message'=>'Pengajuan disimpan.']);
    }
    public function approve(Request $r,int $id){
        $c=new HotelContext($r);$c->authorize('employees.manage');$d=$r->validate(['status'=>['required',Rule::in(['approved','rejected'])]]);
        DB::transaction(function()use($c,$d,$id){$row=$c->table('leave_requests')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);if($row->status!=='pending')throw ValidationException::withMessages(['status'=>'Pengajuan sudah diproses.']);$c->table('leave_requests')->where('id',$id)->update([...$d,'approved_by'=>auth()->id(),'updated_at'=>now()]);$c->audit('Izin/cuti '.$d['status'],'leave_requests',$id);});return response()->json(['message'=>'Pengajuan diperbarui.']);
    }
}
