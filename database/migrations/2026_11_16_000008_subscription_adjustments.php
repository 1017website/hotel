<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_adjustments',function(Blueprint $t){
            $t->id();$t->foreignId('hotel_id')->constrained();$t->foreignId('user_id')->constrained();
            $t->uuid('request_key')->unique();$t->unsignedInteger('days');
            $t->dateTime('before_ends_at')->nullable();$t->dateTime('after_ends_at');$t->text('reason');$t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('subscription_adjustments'); }
};
