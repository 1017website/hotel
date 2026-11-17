<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('address')->nullable();
            $t->string('timezone')->default('Asia/Jakarta'); $t->boolean('is_demo')->default(false);
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->string('name');
            $t->json('permissions'); $t->boolean('is_owner')->default(false); $t->timestamps();
            $t->unique(['hotel_id', 'name']);
        });
        Schema::create('hotel_user', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->foreignId('role_id')->constrained(); $t->unique(['hotel_id', 'user_id']);
        });
        Schema::create('rooms', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->string('number');
            $t->string('type'); $t->unsignedInteger('floor'); $t->unsignedInteger('capacity')->default(2);
            $t->unsignedBigInteger('rate'); $t->json('facilities');
            $t->string('housekeeping')->default('clean'); $t->boolean('out_of_service')->default(false);
            $t->string('assigned_to')->nullable(); $t->text('notes')->nullable(); $t->timestamps();
            $t->unique(['hotel_id', 'number']); $t->index(['hotel_id', 'housekeeping']);
        });
        Schema::create('reservations', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->foreignId('room_id')->constrained();
            $t->string('code')->unique(); $t->string('guest'); $t->string('phone')->nullable();
            $t->date('arrival'); $t->date('departure'); $t->unsignedInteger('adults')->default(1);
            $t->string('source')->default('Langsung'); $t->string('external_id')->nullable();
            $t->string('status')->default('confirmed'); $t->unsignedBigInteger('total');
            $t->text('notes')->nullable(); $t->timestamp('checked_in_at')->nullable();
            $t->timestamp('checked_out_at')->nullable(); $t->timestamps();
            $t->index(['hotel_id', 'arrival', 'departure']); $t->index(['room_id', 'status', 'arrival']);
            $t->unique(['hotel_id', 'source', 'external_id']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->foreignId('reservation_id')->constrained();
            $t->unsignedBigInteger('amount'); $t->string('method'); $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('food_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->string('name');
            $t->string('category'); $t->unsignedBigInteger('price'); $t->boolean('available')->default(true);
            $t->timestamps();
        });
        Schema::create('food_orders', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained();
            $t->foreignId('reservation_id')->constrained(); $t->json('items');
            $t->unsignedBigInteger('total'); $t->string('status')->default('new');
            $t->text('notes')->nullable(); $t->timestamps();
        });
        Schema::create('employees', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->string('name');
            $t->string('department'); $t->string('position'); $t->string('phone')->nullable();
            $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('shifts', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->foreignId('employee_id')->constrained();
            $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->text('notes')->nullable(); $t->timestamps();
            $t->index(['hotel_id', 'starts_at']);
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->foreignId('employee_id')->constrained();
            $t->date('date'); $t->dateTime('clock_in'); $t->dateTime('clock_out')->nullable();
            $t->timestamps(); $t->unique(['hotel_id', 'employee_id', 'date']);
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->string('description');
            $t->string('category'); $t->unsignedBigInteger('amount'); $t->date('date');
            $t->string('status')->default('pending'); $t->foreignId('user_id')->constrained();
            $t->foreignId('approved_by')->nullable()->constrained('users'); $t->timestamps();
        });
        Schema::create('inventory', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->string('name');
            $t->string('unit'); $t->unsignedInteger('quantity'); $t->unsignedInteger('minimum'); $t->timestamps();
        });
        Schema::create('maintenance', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->foreignId('room_id')->constrained();
            $t->string('description'); $t->string('assigned_to')->nullable();
            $t->string('status')->default('open'); $t->timestamps();
        });
        Schema::create('channel_connections', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->string('provider');
            $t->string('property_id')->nullable(); $t->string('connection_mode')->default('channel_manager');
            $t->string('partner_name')->nullable(); $t->text('credentials')->nullable();
            $t->json('mapping')->nullable(); $t->string('status')->default('not_connected');
            $t->timestamp('last_synced_at')->nullable(); $t->timestamps();
            $t->unique(['hotel_id', 'provider']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action'); $t->string('entity'); $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('details')->nullable(); $t->timestamp('created_at')->useCurrent();
            $t->index(['hotel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'channel_connections', 'maintenance', 'inventory', 'expenses', 'attendances', 'shifts', 'employees', 'food_orders', 'food_items', 'payments', 'reservations', 'rooms', 'hotel_user', 'roles', 'hotels'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
