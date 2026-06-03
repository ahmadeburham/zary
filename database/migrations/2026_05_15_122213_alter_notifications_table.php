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
        Schema::table('notifications', function (Blueprint $table) {


            $table->timestamp('failed_at')
                ->nullable()
                ->after('updated_at');

            $table->text('error_message')
                ->nullable()
                ->after('failed_at');

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {


            $table->dropColumn([
                'failed_at',
                'error_message',
            ]);
        });

    }
};
