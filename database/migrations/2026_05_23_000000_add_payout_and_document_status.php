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
        // Add payout_info to users
        Schema::table('users', function (Blueprint $table) {
            $table->text('payout_info')->nullable()->after('fcm_token');
        });

        // Add status and rejection_reason to identity_documents
        Schema::table('identity_documents', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->after('is_verified');
            $table->text('rejection_reason')->nullable()->after('status');
        });

        // Add status and rejection_reason to apartment_documents
        Schema::table('apartment_documents', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->after('document_type');
            $table->text('rejection_reason')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('payout_info');
        });

        Schema::table('identity_documents', function (Blueprint $table) {
            $table->dropColumn(['status', 'rejection_reason']);
        });

        Schema::table('apartment_documents', function (Blueprint $table) {
            $table->dropColumn(['status', 'rejection_reason']);
        });
    }
};
