<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('user_profiles') && !Schema::hasColumn('user_profiles', 'identity_ocr_locked')) {
            Schema::table('user_profiles', function (Blueprint $table) {
                $table->boolean('identity_ocr_locked')->default(false)->after('id_expiry_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_profiles', 'identity_ocr_locked')) {
            Schema::table('user_profiles', function (Blueprint $table) {
                $table->dropColumn('identity_ocr_locked');
            });
        }
    }
};
