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
        // Add gender to identity_verifications for audit trail
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->string('gender')->nullable()->after('address');
        });

        // Add name to user_profiles for persistent storage
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->dropColumn('gender');
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
