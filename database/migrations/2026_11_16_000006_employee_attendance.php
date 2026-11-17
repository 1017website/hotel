<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('attendance_code', 40)->nullable();
            $t->unique(['hotel_id', 'attendance_code']);
            $t->unique(['hotel_id', 'user_id']);
        });
        Schema::create('attendance_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('filename');
            $t->string('file_hash', 64);
            $t->unsignedInteger('inserted')->default(0);
            $t->unsignedInteger('completed')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'file_hash']);
        });
        Schema::table('attendances', function (Blueprint $t) {
            $t->string('source', 20)->default('manual');
            $t->foreignId('attendance_import_id')->nullable()->constrained('attendance_imports');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $t) {
            $t->dropConstrainedForeignId('attendance_import_id');
            $t->dropColumn('source');
        });
        Schema::dropIfExists('attendance_imports');
        Schema::table('employees', function (Blueprint $t) {
            $t->dropUnique(['hotel_id', 'attendance_code']);
            $t->dropUnique(['hotel_id', 'user_id']);
            $t->dropColumn('attendance_code');
        });
    }
};
