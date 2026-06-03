<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') return;

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('payout_type')->nullable()->after('gender');
            $table->string('payout_number')->nullable()->after('payout_type');
            $table->boolean('has_paid_platform_fee')->default(false)->after('payout_number');
        });

        Schema::table('apartment_members', function (Blueprint $table) {
            $table->timestamp('payment_deadline')->nullable()->after('membership_status');
        });

        DB::statement("ALTER TABLE apartments MODIFY COLUMN `status` ENUM('draft','open','closed','full','rented','refused') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['name', 'payout_type', 'payout_number', 'has_paid_platform_fee']);
        });
        Schema::table('apartment_members', function (Blueprint $table) {
            $table->dropColumn('payment_deadline');
        });
        DB::statement("ALTER TABLE apartments MODIFY COLUMN `status` ENUM('draft','open','closed','full','refused') NOT NULL DEFAULT 'draft'");
    }
};
