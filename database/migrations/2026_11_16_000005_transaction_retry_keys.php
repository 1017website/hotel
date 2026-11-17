<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['payments', 'stock_movements'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('request_key')->nullable();
                $t->unique(['hotel_id', 'request_key']);
            });
        }
    }

    public function down(): void
    {
        foreach (['payments', 'stock_movements'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['hotel_id', 'request_key']);
                $t->dropColumn('request_key');
            });
        }
    }
};
