<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        DB::statement("ALTER TABLE apartments MODIFY COLUMN `status` ENUM('draft','open','closed','full','rented','refused','closed_uploading_contracts') NOT NULL DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        DB::statement("ALTER TABLE apartments MODIFY COLUMN `status` ENUM('draft','open','closed','full','rented','refused') NOT NULL DEFAULT 'draft'");
    }
};
