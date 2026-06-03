<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expand the 'status' and 'verification_status' enums to include 'refused'.
 *
 * MySQL requires ALTER TABLE ... MODIFY COLUMN to change enum values.
 * We use raw DB statements for idempotency and compatibility.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        // Add 'refused' to status enum (keeps existing values)
        DB::statement("
            ALTER TABLE apartments
            MODIFY COLUMN `status`
            ENUM('draft', 'open', 'closed', 'full', 'refused') NOT NULL DEFAULT 'draft'
        ");

        // Add 'refused' to verification_status enum (keeps existing values)
        DB::statement("
            ALTER TABLE apartments
            MODIFY COLUMN `verification_status`
            ENUM('pending', 'approved', 'rejected', 'suspended', 'refused') NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        // Revert to original enums (rows with 'refused' will cause errors — must be cleaned first)
        DB::statement("
            ALTER TABLE apartments
            MODIFY COLUMN `status`
            ENUM('draft', 'open', 'closed', 'full') NOT NULL DEFAULT 'draft'
        ");

        DB::statement("
            ALTER TABLE apartments
            MODIFY COLUMN `verification_status`
            ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending'
        ");
    }
};
