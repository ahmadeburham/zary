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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_profile_completed')->default(false)->after('gender');
            $table->integer('onboarding_screen')->default(1)->after('is_profile_completed');
            $table->boolean('is_verified')->default(false)->after('onboarding_screen');
            $table->string('google_id')->nullable()->unique()->after('facebook_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_profile_completed',
                'onboarding_screen',
                'is_verified',
                'google_id',
            ]);
        });
    }
};
