<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants_contracts', function (Blueprint $table) {
            $table->string('move_in_date')->nullable()->after('status');
            $table->string('lease_duration')->nullable()->after('move_in_date');
            $table->unsignedInteger('occupants')->nullable()->after('lease_duration');
            $table->text('message')->nullable()->after('occupants');
        });
    }

    public function down(): void
    {
        Schema::table('tenants_contracts', function (Blueprint $table) {
            $table->dropColumn(['move_in_date', 'lease_duration', 'occupants', 'message']);
        });
    }
};
