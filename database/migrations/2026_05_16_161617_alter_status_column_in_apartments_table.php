<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        Schema::table('apartments', function (Blueprint $table) {
            DB::statement("ALTER TABLE apartments MODIFY COLUMN status ENUM('draft','open','closed','full','ready','rented') NOT NULL DEFAULT 'draft'");
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        Schema::table('apartments', function (Blueprint $table) {
            DB::statement("ALTER TABLE apartments MODIFY COLUMN status ENUM('draft','open','closed','full','ready','rented') NOT NULL DEFAULT 'draft'");
        });
    }
};
