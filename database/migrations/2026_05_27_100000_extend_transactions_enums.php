<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends transactions.type to include 'admin_commission'
 * and transactions.status to include 'pending_manual'.
 *
 * MySQL only — SQLite uses plain string columns and accepts any value.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN `type` ENUM('charge','refund','payout_owner','payout_platform','admin_commission') NOT NULL");
            DB::statement("ALTER TABLE transactions MODIFY COLUMN `status` ENUM('success','failed','pending','pending_manual') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN `type` ENUM('charge','refund','payout_owner','payout_platform') NOT NULL");
            DB::statement("ALTER TABLE transactions MODIFY COLUMN `status` ENUM('success','failed','pending') NOT NULL DEFAULT 'pending'");
        }
    }
};
