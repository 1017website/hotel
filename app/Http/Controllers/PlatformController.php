<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\HotelPassword;
use App\Services\SubscriptionAccess;
use App\Services\SubscriptionService;
use App\Support\PlatformContext;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformController extends Controller
{
    public function index(Request $r) { new PlatformContext($r); return response()->view('saas.admin')->header('Cache-Control','no-store'); }

    public function data(Request $r)
    {
        $c = new PlatformContext($r);
        $d = $r->validate([
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:100000',
            'access' => ['nullable', Rule::in(['active', 'pending', 'expired', 'suspended', 'maintenance'])],
        ]);
        $settings = DB::table('saas_settings')->find(1);
        $now = now()->utc()->format('Y-m-d H:i:s');
        $tenants = null;
        if ($c->can('tenants.view')) {
            $query = DB::table('saas_accounts as a')->join('hotels as h','h.id','=','a.hotel_id')->join('users as u','u.id','=','a.owner_user_id')->join('subscription_plans as p','p.id','=','a.plan_id')
                ->select('a.*','h.name as hotel_name','h.is_demo','u.name as owner_name','u.email as owner_email','p.name as plan_name');
            if (isset($d['q'])) {
                $term = '%'.$d['q'].'%';
                $query->where(fn ($q) => $q->where('h.name', 'like', $term)
                    ->orWhere('u.name', 'like', $term)->orWhere('u.email', 'like', $term));
            }
            if (isset($d['access'])) {
                $this->filterTenantAccess($query, $d['access'], $settings, $now);
            }
            $tenants = $query->orderByDesc('a.id')->paginate(25);
            $tenants->getCollection()->each(function ($a) use ($settings) { $hotel=(object)['id'=>$a->hotel_id,'is_demo'=>$a->is_demo]; $a->access_state=SubscriptionAccess::state($hotel,$a,$settings)['state']; });
        }
        return response()->json([
            'user'=>$r->user()->only('name','email'), 'is_super'=>(bool)$c->admin->is_super,
            'permissions'=>array_values(array_filter(PlatformContext::PERMISSIONS,fn($p)=>$c->can($p))),
            'permission_options'=>PlatformContext::PERMISSIONS, 'tenants'=>$tenants,
            'plans'=>DB::table('subscription_plans')->orderBy('id')->get(),
            'settings'=>$settings,
            'summary'=>$c->can('tenants.view')?['total'=>DB::table('saas_accounts')->count(),'active'=>DB::table('saas_accounts')->where('status','active')->where('ends_at','>',$now)->count(),'expired'=>DB::table('saas_accounts')->where('status','active')->where('ends_at','<=',$now)->count(),'suspended'=>DB::table('saas_accounts')->where('status','suspended')->count()]:null,
            'admins'=>$c->admin->is_super?DB::table('platform_admins')->join('users','users.id','=','platform_admins.user_id')->select('platform_admins.id','users.name','users.email','platform_admins.is_super','platform_admins.active','platform_admins.permissions')->get():[],
            'audit'=>$c->can('tenants.view')?DB::table('platform_audit_logs')->leftJoin('users','users.id','=','platform_audit_logs.user_id')->select('platform_audit_logs.*','users.name as actor')->orderByDesc('platform_audit_logs.id')->limit(25)->get():[],
        ])->header('Cache-Control','no-store');
    }

    private function filterTenantAccess(Builder $query, string $access, ?object $settings, string $now): void
    {
        if ($settings && SubscriptionAccess::maintenance($settings)) {
            if ($access !== 'maintenance') $query->whereRaw('1 = 0');
            return;
        }
        if ($access === 'suspended') {
            $query->where('a.status', 'suspended');
            return;
        }
        $query->where('a.status', '!=', 'suspended');
        if ($access === 'maintenance') {
            $query->where('a.maintenance', true)
                ->where(fn ($q) => $q->whereNull('a.maintenance_until')->orWhere('a.maintenance_until', '>', $now));
            return;
        }
        $query->where(fn ($q) => $q->where('a.maintenance', false)->orWhere('a.maintenance_until', '<=', $now));
        match ($access) {
            'pending' => $query->where('a.status', 'pending'),
            'expired' => $query->where('a.status', '!=', 'pending')
                ->where(fn ($q) => $q->whereNull('a.ends_at')->orWhere('a.ends_at', '<=', $now)),
            'active' => $query->where('a.status', '!=', 'pending')->where('a.ends_at', '>', $now),
        };
    }

    public function detail(Request $r,int $hotel)
    {
        $c=new PlatformContext($r);$c->authorize('tenants.view');
        $a=DB::table('saas_accounts')->where('hotel_id',$hotel)->first();abort_unless($a,404);
        return response()->json(['account'=>$a,'invoices'=>$c->can('invoices.manage')?DB::table('subscription_invoices')->where('hotel_id',$hotel)->orderByDesc('id')->limit(30)->get():[],'usage'=>['rooms'=>DB::table('rooms')->where('hotel_id',$hotel)->count(),'users'=>DB::table('hotel_user')->where('hotel_id',$hotel)->count()]])->header('Cache-Control','no-store');
    }

    public function createTenant(Request $r,SubscriptionService $service)
    {
        (new PlatformContext($r))->authorize('tenants.manage');
        $data=$this->registrationData($r);
        $created=$service->register($data);
        return response()->json(['message'=>'Hotel dan Owner dibuat. Akses menunggu pembayaran atau aktivasi admin.','hotel_id'=>$created->hotel_id]);
    }

    public function registrationData(Request $r): array
    {
        if(is_string($r->input('email')))$r->merge(['email'=>strtolower(trim($r->input('email')))]);
        return $r->validate(['hotel_name'=>'required|string|max:120','name'=>'required|string|max:120','email'=>'required|email|max:200|unique:users,email','password'=>['required','string','min:12','confirmed',new HotelPassword],'plan_id'=>['required','integer',Rule::exists('subscription_plans','id')->where('published',true)],'months'=>['required','integer',Rule::in([1,12])]]);
    }

    public function plan(Request $r,?int $id=null)
    {
        (new PlatformContext($r))->authorize('plans.manage');
        $d=$r->validate(['name'=>'required|string|max:100','slug'=>['required','regex:/^[a-z0-9-]{2,80}$/',Rule::unique('subscription_plans')->ignore($id)],'description'=>'nullable|string|max:1000','monthly_price'=>'required|integer|min:0|max:1000000000','yearly_price'=>'required|integer|min:0|max:1000000000','room_limit'=>'required|integer|min:1|max:10000','user_limit'=>'required|integer|min:1|max:5000','published'=>'required|boolean']);
        if($d['published']&&max($d['monthly_price'],$d['yearly_price'])<1)throw ValidationException::withMessages(['price'=>'Paket terbit memerlukan setidaknya satu harga periode lebih dari nol.']);
        DB::transaction(function()use($d,&$id){if($id){abort_unless(DB::table('subscription_plans')->where('id',$id)->lockForUpdate()->first(),404);DB::table('subscription_plans')->where('id',$id)->update([...$d,'updated_at'=>now()]);}else{$id=DB::table('subscription_plans')->insertGetId([...$d,'created_at'=>now(),'updated_at'=>now()]);}PlatformContext::audit('Paket langganan disimpan',null,['plan_id'=>$id]);});
        return response()->json(['message'=>'Paket disimpan. Harga/batas baru dipakai pada tagihan baru; tagihan dan periode yang sudah tercatat tetap menggunakan snapshot.']);
    }

    public function access(Request $r,int $hotel)
    {
        $c=new PlatformContext($r);
        $d=$r->validate(['action'=>['required',Rule::in(['extend','suspend','unsuspend','maintenance_on','maintenance_off'])],'reason'=>'required|string|min:5|max:1000','days'=>'required_if:action,extend|nullable|integer|min:1|max:3660','until'=>'nullable|date','request_key'=>'required_if:action,extend|nullable|uuid']);
        $c->authorize(str_starts_with($d['action'],'maintenance')?'maintenance.manage':'subscriptions.manage');
        $until=$d['action']==='maintenance_on'?$this->until($d['until']??null):null;
        DB::transaction(function()use($d,$hotel,$until){$a=DB::table('saas_accounts')->where('hotel_id',$hotel)->lockForUpdate()->first();abort_unless($a,404);$changes=['updated_at'=>now()];
            switch($d['action']){
                case 'extend':
                    $previous=DB::table('subscription_adjustments')->where('request_key',$d['request_key'])->first();
                    if($previous){if($previous->hotel_id!==$hotel)throw ValidationException::withMessages(['request_key'=>'Referensi perpanjangan sudah dipakai untuk hotel lain.']);return;}
                    $base=$a->ends_at&&$a->ends_at>now()->utc()->format('Y-m-d H:i:s')?Carbon::parse($a->ends_at,'UTC'):now()->utc();
                    $changes+=['ends_at'=>$base->addDays((int)$d['days']),'status'=>$a->status==='suspended'?'suspended':'active'];
                    DB::table('subscription_adjustments')->insert(['hotel_id'=>$hotel,'user_id'=>auth()->id(),'request_key'=>$d['request_key'],'days'=>$d['days'],'before_ends_at'=>$a->ends_at,'after_ends_at'=>$changes['ends_at'],'reason'=>$d['reason'],'created_at'=>now(),'updated_at'=>now()]);break;
                case 'suspend':$changes+=['status'=>'suspended','suspend_reason'=>$d['reason']];break;
                case 'unsuspend':$changes+=['status'=>$a->ends_at?'active':'pending','suspend_reason'=>null];break;
                case 'maintenance_on':$changes+=['maintenance'=>true,'maintenance_until'=>$until,'maintenance_message'=>$d['reason']];break;
                case 'maintenance_off':$changes+=['maintenance'=>false,'maintenance_until'=>null,'maintenance_message'=>null];break;
            }
            DB::table('saas_accounts')->where('id',$a->id)->update($changes);PlatformContext::audit('Akses hotel: '.$d['action'],$hotel,['reason'=>$d['reason'],'days'=>$d['days']??null,'until'=>$until?->toDateTimeString()]);
        },3);
        return response()->json(['message'=>'Akses hotel diperbarui dan dicatat pada audit. Perpanjangan manual tidak mencatat penerimaan uang.']);
    }

    public function issue(Request $r,int $hotel,SubscriptionService $service)
    {
        (new PlatformContext($r))->authorize('invoices.manage');
        $d=$r->validate(['plan_id'=>'required|integer','months'=>['required','integer',Rule::in([1,12])]]);
        $invoice=$service->issue($hotel,(int)$d['plan_id'],(int)$d['months']);return response()->json(['message'=>'Tagihan langganan tersedia.','invoice'=>$invoice]);
    }

    public function invoice(Request $r,int $id,SubscriptionService $service)
    {
        (new PlatformContext($r))->authorize('invoices.manage');
        $d=$r->validate(['action'=>['required',Rule::in(['paid','void'])],'reference'=>'required|string|min:3|max:200','method'=>['required_if:action,paid','nullable',Rule::in(['Transfer','Tunai','Lainnya'])]]);
        if($d['action']==='paid')$service->paid($id,$d['reference'],$d['method']);
        else DB::transaction(function()use($id,$d){$initial=DB::table('subscription_invoices')->find($id);abort_unless($initial,404);DB::table('saas_accounts')->where('id',$initial->saas_account_id)->lockForUpdate()->first();$i=DB::table('subscription_invoices')->where('id',$id)->lockForUpdate()->first();if($i->status!=='unpaid')throw ValidationException::withMessages(['invoice'=>'Hanya tagihan belum dibayar yang dapat dibatalkan.']);DB::table('subscription_invoices')->where('id',$id)->update(['status'=>'void','notes'=>$d['reference'],'updated_at'=>now()]);PlatformContext::audit('Tagihan langganan dibatalkan',$i->hotel_id,['invoice_id'=>$id,'reason'=>$d['reference']]);});
        return response()->json(['message'=>$d['action']==='paid'?'Pembayaran terverifikasi. Masa aktif diperbarui satu kali. Suspend/maintenance tidak dibuka otomatis.':'Tagihan dibatalkan.']);
    }

    public function settings(Request $r)
    {
        (new PlatformContext($r))->authorize('settings.manage');
        $data = $r->validate([
            'signup_enabled' => 'required|boolean',
            'support_contact' => 'nullable|string|max:200',
            'payment_instructions' => 'nullable|string|max:3000',
        ]);

        DB::transaction(function () use ($data) {
            $settings = DB::table('saas_settings')->lockForUpdate()->find(1);
            $instructions = array_key_exists('payment_instructions', $data)
                ? $data['payment_instructions']
                : $settings->payment_instructions;

            if ($data['signup_enabled'] && blank($instructions)) {
                throw ValidationException::withMessages([
                    'payment_instructions' => 'Isi instruksi pembayaran sebelum membuka pendaftaran mandiri.',
                ]);
            }

            DB::table('saas_settings')->where('id', 1)->update($data + ['updated_at' => now()]);
            PlatformContext::audit('Pengaturan SaaS diperbarui');
        });

        return response()->json(['message' => 'Pengaturan SaaS disimpan.']);
    }

    public function maintenance(Request $r)
    {
        (new PlatformContext($r))->authorize('maintenance.manage');$d=$r->validate(['enabled'=>'required|boolean','reason'=>'required|string|min:5|max:1000','until'=>'nullable|date']);$until=$d['enabled']?$this->until($d['until']??null):null;
        DB::transaction(function()use($d,$until){DB::table('saas_settings')->where('id',1)->update(['maintenance'=>$d['enabled'],'maintenance_until'=>$d['enabled']?$until:null,'maintenance_message'=>$d['enabled']?$d['reason']:null,'updated_at'=>now()]);PlatformContext::audit('Maintenance global diperbarui',null,['enabled'=>$d['enabled'],'reason'=>$d['reason']]);});return response()->json(['message'=>'Maintenance global diperbarui. Panel SaaS dan halaman langganan tetap tersedia.']);
    }

    public function admin(Request $r,?int $id=null)
    {
        (new PlatformContext($r))->super();
        $rules=['permissions'=>'present|array','permissions.*'=>[Rule::in(PlatformContext::PERMISSIONS)],'active'=>'required|boolean'];
        if(!$id)$rules+=['name'=>'required|string|max:120','email'=>'required|email|max:200|unique:users,email','password'=>['required','string','min:12','confirmed',new HotelPassword]];
        if(is_string($r->input('email')))$r->merge(['email'=>strtolower(trim($r->input('email')))]);
        $d=$r->validate($rules);
        DB::transaction(function()use($id,$d){$permissions=$d['permissions'];if(array_intersect($permissions,['tenants.manage','subscriptions.manage','invoices.manage','maintenance.manage']))$permissions[]='tenants.view';$values=['permissions'=>json_encode(array_values(array_unique($permissions))),'active'=>$d['active'],'updated_at'=>now()];
            if($id){$a=DB::table('platform_admins')->where('id',$id)->lockForUpdate()->first();abort_unless($a,404);abort_if($a->is_super,422,'Super Admin tidak diubah melalui formulir sub-admin.');DB::table('platform_admins')->where('id',$id)->update($values);if(!$d['active'])DB::table('sessions')->where('user_id',$a->user_id)->delete();}
            else{$user=User::create(['name'=>$d['name'],'email'=>$d['email'],'password'=>$d['password']]);DB::table('platform_admins')->insert([...$values,'user_id'=>$user->id,'is_super'=>false,'created_at'=>now()]);}
            PlatformContext::audit('Sub-admin SaaS disimpan',null,['admin_id'=>$id,'permissions'=>$permissions,'active'=>$d['active']]);
        },3);return response()->json(['message'=>'Sub-admin disimpan. Akses hotel dan data tamu tidak diberikan.']);
    }

    private function until(?string $value): ?Carbon
    {
        if(!$value)return null;$date=Carbon::parse($value,'Asia/Jakarta')->utc();if($date->lte(now()->utc()))throw ValidationException::withMessages(['until'=>'Batas maintenance harus di masa depan (waktu WIB).']);return $date;
    }
}
