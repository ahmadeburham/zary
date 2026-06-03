<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        DB::statement("
            ALTER TABLE apartment_members
            MODIFY COLUMN `membership_status`
            ENUM('pending', 'active', 'cancelled') NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        // Notice: If there are rows with 'pending', rolling back will fail 
        // unless you manually update or delete those rows first.
        DB::statement("
            ALTER TABLE apartment_members
            MODIFY COLUMN `membership_status`
            ENUM('active', 'cancelled') NOT NULL DEFAULT 'active'
        ");
    }
};
