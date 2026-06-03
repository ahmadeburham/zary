<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            // Index for status filtering (if not exists)
            if (!$this->indexExists('identity_verifications', 'overall_status')) {
                $table->index('overall_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            if ($this->indexExists('identity_verifications', 'overall_status')) {
                $table->dropIndex(['overall_status']);
            }
        });
    }

    private function indexExists($table, $index): bool
    {
        $indexes = \DB::select("PRAGMA index_list({$table})");
        foreach ($indexes as $idx) {
            if ($idx->name === "{$table}_{$index}_index") {
                return true;
            }
        }
        return false;
    }
};
