<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') return;

        Schema::table('apartments', function (Blueprint $table) {
            if (!Schema::hasColumn('apartments', 'location')) {
                DB::statement('ALTER TABLE apartments ADD COLUMN `location` POINT NOT NULL AFTER `is_furnished`');
            }
            if (Schema::hasColumn('apartments', 'latitude')) {
                $table->dropColumn('latitude');
            }
            if (Schema::hasColumn('apartments', 'longitude')) {
                $table->dropColumn('longitude');
            }
            if (!Schema::hasColumn('apartments', 'rent_duration')) {
                $table->unsignedInteger('rent_duration')->default(1)->after('verification_status');
            }
            if (!Schema::hasColumn('apartments', 'rented_at')) {
                $table->timestamp('rented_at')->nullable()->after('rent_duration');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;

        Schema::table('apartments', function (Blueprint $table) {
            if (Schema::hasColumn('apartments', 'location')) {
                $table->dropColumn('location');
            }
            if (!Schema::hasColumn('apartments', 'latitude')) {
                $table->decimal('latitude', 10, 8)->after('is_furnished');
            }
            if (!Schema::hasColumn('apartments', 'longitude')) {
                $table->decimal('longitude', 11, 8)->after('latitude');
            }
            if (Schema::hasColumn('apartments', 'rent_duration')) {
                $table->dropColumn('rent_duration');
            }
            if (Schema::hasColumn('apartments', 'rented_at')) {
                $table->dropColumn('rented_at');
            }
        });
    }
};
