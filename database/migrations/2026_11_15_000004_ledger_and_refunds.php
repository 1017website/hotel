<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{
        Schema::create('payment_refunds',function(Blueprint $t){$t->id();$t->foreignId('hotel_id')->constrained();$t->foreignId('reservation_id')->constrained();$t->unsignedBigInteger('amount');$t->string('method');$t->text('notes');$t->uuid('request_key');$t->foreignId('user_id')->constrained();$t->timestamps();$t->unique(['hotel_id','request_key']);});
        Schema::create('ledger_accounts',function(Blueprint $t){$t->id();$t->foreignId('hotel_id')->constrained();$t->string('code',12);$t->string('name');$t->string('type');$t->timestamps();$t->unique(['hotel_id','code']);});
        Schema::create('journal_entries',function(Blueprint $t){$t->id();$t->foreignId('hotel_id')->constrained();$t->string('reference');$t->date('date');$t->string('description');$t->foreignId('user_id')->nullable()->constrained();$t->timestamps();$t->unique(['hotel_id','reference']);});
        Schema::create('journal_lines',function(Blueprint $t){$t->id();$t->foreignId('hotel_id')->constrained();$t->foreignId('journal_entry_id')->constrained();$t->foreignId('account_id')->constrained('ledger_accounts');$t->unsignedBigInteger('debit')->default(0);$t->unsignedBigInteger('credit')->default(0);$t->timestamps();});
    }
    public function down():void{Schema::dropIfExists('journal_lines');Schema::dropIfExists('journal_entries');Schema::dropIfExists('ledger_accounts');Schema::dropIfExists('payment_refunds');}
};
