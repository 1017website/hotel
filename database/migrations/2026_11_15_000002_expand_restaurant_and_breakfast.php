<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('food_orders', function (Blueprint $t) {
            $t->foreignId('reservation_id')->nullable()->change();
            $t->string('order_type')->default('room_service'); $t->string('table_label')->nullable();
            $t->string('customer')->nullable(); $t->string('payment_status')->default('room');
            $t->string('payment_method')->nullable(); $t->unsignedBigInteger('subtotal')->default(0);
            $t->unsignedBigInteger('discount')->default(0); $t->unsignedBigInteger('tax')->default(0);
            $t->unsignedBigInteger('service')->default(0); $t->unsignedBigInteger('tendered')->default(0);
            $t->timestamp('paid_at')->nullable(); $t->uuid('request_key')->nullable();
            $t->unique(['hotel_id','request_key']);
        });
        Schema::table('hotels', function (Blueprint $t) {
            $t->unsignedInteger('restaurant_tax')->default(0); $t->unsignedInteger('restaurant_service')->default(0);
            $t->time('breakfast_start')->default('06:00'); $t->time('breakfast_end')->default('10:00');
        });
        Schema::table('reservations', fn (Blueprint $t) => $t->unsignedInteger('breakfast_guests')->default(0));
        Schema::create('breakfast_visits', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->foreignId('reservation_id')->constrained();
            $t->date('service_date'); $t->unsignedInteger('quantity'); $t->foreignId('user_id')->constrained();
            $t->uuid('request_key'); $t->text('notes')->nullable(); $t->timestamps();
            $t->unique(['hotel_id','request_key']); $t->index(['reservation_id','service_date']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('breakfast_visits');
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn('breakfast_guests'));
        Schema::table('hotels', fn (Blueprint $t) => $t->dropColumn(['restaurant_tax','restaurant_service','breakfast_start','breakfast_end']));
        Schema::table('food_orders', function (Blueprint $t) {
            $t->dropUnique(['hotel_id','request_key']);
            $t->dropColumn(['order_type','table_label','customer','payment_status','payment_method','subtotal','discount','tax','service','tendered','paid_at','request_key']);
        });
    }
};
