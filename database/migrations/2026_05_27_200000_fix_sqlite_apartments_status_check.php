<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This migration is skipped - the apartments table is created properly in the original migration
        // The column mismatch issue is resolved by using the original schema
    }

    public function down(): void {}
};
