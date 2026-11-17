<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->unique()->constrained();
            $t->boolean('is_super')->default(false); $t->boolean('active')->default(true);
            $t->json('permissions'); $t->timestamps();
        });
        Schema::create('subscription_plans', function (Blueprint $t) {
            $t->id(); $t->string('name', 100); $t->string('slug', 80)->unique();
            $t->unsignedBigInteger('monthly_price')->default(0); $t->unsignedBigInteger('yearly_price')->default(0);
            $t->unsignedInteger('room_limit')->default(50); $t->unsignedInteger('user_limit')->default(20);
            $t->text('description')->nullable(); $t->boolean('published')->default(false); $t->timestamps();
        });
        Schema::create('saas_accounts', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->unique()->constrained();
            $t->foreignId('owner_user_id')->unique()->constrained('users');
            $t->foreignId('plan_id')->constrained('subscription_plans');
            $t->string('status')->default('pending'); $t->dateTime('ends_at')->nullable();
            $t->unsignedInteger('room_limit'); $t->unsignedInteger('user_limit');
            $t->text('suspend_reason')->nullable(); $t->boolean('maintenance')->default(false);
            $t->dateTime('maintenance_until')->nullable(); $t->text('maintenance_message')->nullable();
            $t->timestamps(); $t->index(['status', 'ends_at']);
        });
        Schema::create('subscription_invoices', function (Blueprint $t) {
            $t->id(); $t->foreignId('hotel_id')->constrained(); $t->foreignId('saas_account_id')->constrained();
            $t->foreignId('plan_id')->constrained('subscription_plans'); $t->string('code')->unique();
            $t->string('plan_name'); $t->unsignedInteger('months'); $t->unsignedBigInteger('amount');
            $t->unsignedInteger('room_limit'); $t->unsignedInteger('user_limit');
            $t->string('status')->default('unpaid'); $t->dateTime('due_at');
            $t->string('payment_reference', 200)->nullable(); $t->dateTime('reported_at')->nullable();
            $t->string('payment_method', 30)->nullable(); $t->dateTime('paid_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->dateTime('period_starts_at')->nullable(); $t->dateTime('period_ends_at')->nullable();
            $t->text('notes')->nullable(); $t->timestamps(); $t->index(['hotel_id', 'status']);
        });
        Schema::create('platform_audit_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->nullable()->constrained(); $t->string('action');
            $t->foreignId('hotel_id')->nullable()->constrained(); $t->json('details'); $t->timestamps();
        });
        Schema::create('saas_settings', function (Blueprint $t) {
            $t->id(); $t->boolean('signup_enabled')->default(false); $t->string('support_contact', 200)->nullable();
            $t->text('payment_instructions')->nullable(); $t->boolean('maintenance')->default(false);
            $t->dateTime('maintenance_until')->nullable(); $t->text('maintenance_message')->nullable(); $t->timestamps();
        });
        DB::table('saas_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscription_plans')->insert(['name' => 'Paket Hotel', 'slug' => 'hotel', 'description' => 'Draf paket. Tentukan harga dan batas akun/kamar sebelum diterbitkan.', 'monthly_price' => 0, 'yearly_price' => 0, 'room_limit' => 50, 'user_limit' => 20, 'published' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach (['platform_audit_logs', 'subscription_invoices', 'saas_accounts', 'subscription_plans', 'saas_settings', 'platform_admins'] as $table) Schema::dropIfExists($table);
    }
};
