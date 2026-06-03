<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_session_tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('payment_session_id')
                ->constrained('payment_sessions')
                ->cascadeOnDelete();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->decimal('rent_amount', 12, 2)->default(0);

            $table->decimal('insurance_amount', 12, 2)->default(0);

            $table->decimal('total_amount', 12, 2)->default(0);

            $table->enum('payment_method', [
                'card',
                'instapay',
                'fawry',
                'paymob',
            ])->nullable();

            $table->enum('payment_type', [
                'direct',
                'authorization',
            ])->nullable();

            $table->enum('status', [
                'pending',
                'authorized',
                'paid',
                'failed',
                'expired',
                'refunded',
                'capture_failed',
            ])->default('pending');

            $table->boolean('requires_insurance')->default(false);

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index('payment_session_id');
            $table->index('user_id');

            $table->index([
                'payment_session_id',
                'status',
            ]);

            $table->unique([
                'payment_session_id',
                'user_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_session_tenants');
    }
};
