<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('apartment_id')
                ->constrained('apartments')
                ->cascadeOnDelete();

            $table->unsignedInteger('session_no');

            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');

            $table->decimal('rent_amount', 12, 2)->default(0);
            $table->decimal('insurance_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            $table->enum('status', [
                'pending',
                'collecting',
                'ready_to_capture',
                'captured',
                'partially_paid',
                'failed',
                'cancelled',
            ])->default('pending');

            $table->enum('capture_strategy', [
                'instant',
                'authorization',
                'hybird',
            ])->default('authorization');

            $table->date('due_date');

            $table->date('grace_period_until')->nullable();

            $table->timestamp('captured_at')->nullable();

            $table->timestamps();

            $table->index(['apartment_id', 'month', 'year']);
            $table->index('status');

            $table->unique([
                'apartment_id',
                'session_no',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sessions');
    }
};
