<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
return new class extends Migration {
    public function up(): void {
        Schema::table('hotels',function(Blueprint $t){$t->string('public_slug')->nullable()->unique();$t->boolean('public_booking')->default(false);});
        foreach(DB::table('hotels')->get() as $h) DB::table('hotels')->where('id',$h->id)->update(['public_slug'=>Str::slug($h->name).'-'.Str::lower(Str::random(6))]);
        Schema::table('reservations',function(Blueprint $t){$t->unsignedBigInteger('room_total')->default(0);$t->unsignedBigInteger('nightly_rate')->default(0);$t->uuid('public_token')->nullable()->unique();$t->uuid('request_key')->nullable();$t->unique(['hotel_id','request_key']);});
        foreach(DB::table('reservations')->get() as $b){$cost=max(0,$b->total-DB::table('food_orders')->where('reservation_id',$b->id)->sum('total'));$days=max(1,(int)\Carbon\Carbon::parse($b->arrival)->diffInDays($b->departure));DB::table('reservations')->where('id',$b->id)->update(['room_total'=>$cost,'nightly_rate'=>(int)round($cost/$days)]);}
        Schema::create('guest_profiles',function(Blueprint $t){$t->id();$t->foreignId('hotel_id')->constrained();$t->foreignId('reservation_id')->unique()->constrained();$t->text('details');$t->string('identity_last4',4);$t->string('document_path')->nullable();$t->string('document_mime')->nullable();$t->timestamps();});
        Schema::create('deposit_entries',function(Blueprint $t){$t->id();$t->foreignId('hotel_id')->constrained();$t->foreignId('reservation_id')->constrained();$t->string('type');$t->unsignedBigInteger('amount');$t->string('method');$t->text('notes')->nullable();$t->uuid('request_key');$t->foreignId('user_id')->constrained();$t->timestamps();$t->unique(['hotel_id','request_key']);});
        Schema::table('attendances',function(Blueprint $t){$t->dateTime('clock_in')->nullable()->change();$t->string('status')->default('present');$t->text('notes')->nullable();});
        Schema::table('employees',fn(Blueprint $t)=>$t->foreignId('user_id')->nullable()->constrained());
        Schema::create('leave_requests',function(Blueprint $t){$t->id();$t->foreignId('hotel_id')->constrained();$t->foreignId('employee_id')->constrained();$t->date('starts_on');$t->date('ends_on');$t->string('type');$t->text('reason');$t->string('status')->default('pending');$t->foreignId('approved_by')->nullable()->constrained('users');$t->timestamps();});
        Schema::table('inventory',function(Blueprint $t){$t->string('kind')->default('amenity');$t->unsignedInteger('dirty_quantity')->default(0);$t->unsignedInteger('laundry_quantity')->default(0);});
        Schema::create('stock_movements',function(Blueprint $t){$t->id();$t->foreignId('hotel_id')->constrained();$t->foreignId('inventory_id')->constrained('inventory');$t->foreignId('room_id')->nullable()->constrained();$t->string('type');$t->unsignedInteger('quantity');$t->text('notes')->nullable();$t->foreignId('user_id')->constrained();$t->timestamps();});
        Schema::table('food_items',fn(Blueprint $t)=>$t->string('outlet')->default('restaurant'));
        Schema::table('food_orders',fn(Blueprint $t)=>$t->string('outlet')->default('restaurant'));
    }
    public function down(): void {
        Schema::table('food_orders',fn(Blueprint $t)=>$t->dropColumn('outlet'));Schema::table('food_items',fn(Blueprint $t)=>$t->dropColumn('outlet'));
        Schema::dropIfExists('stock_movements');Schema::table('inventory',fn(Blueprint $t)=>$t->dropColumn(['kind','dirty_quantity','laundry_quantity']));
        Schema::dropIfExists('leave_requests');Schema::table('employees',function(Blueprint $t){$t->dropConstrainedForeignId('user_id');});Schema::table('attendances',fn(Blueprint $t)=>$t->dropColumn(['status','notes']));
        Schema::dropIfExists('deposit_entries');Schema::dropIfExists('guest_profiles');Schema::table('reservations',function(Blueprint $t){$t->dropUnique(['hotel_id','request_key']);$t->dropColumn(['room_total','nightly_rate','public_token','request_key']);});Schema::table('hotels',function(Blueprint $t){$t->dropUnique(['public_slug']);$t->dropColumn(['public_slug','public_booking']);});
    }
};
