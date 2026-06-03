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
        Schema::table('user_apartment_contracts', function (Blueprint $table) {
            $table->enum('status', ['pending', 'accepted', 'refused'])
                ->default('pending')
                ->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('user_apartment_contracts', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
