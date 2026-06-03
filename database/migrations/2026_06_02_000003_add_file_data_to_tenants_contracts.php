<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants_contracts', function (Blueprint $table) {
            $table->binary('file_data')->nullable()->after('path');
            $table->string('mime_type', 100)->nullable()->after('file_data');
            $table->integer('file_size')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('tenants_contracts', function (Blueprint $table) {
            $table->dropColumn(['file_data', 'mime_type', 'file_size']);
        });
    }
};
