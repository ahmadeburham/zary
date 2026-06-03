<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('payment_session_tenant_id')
                ->constrained('payment_session_tenants')
                ->cascadeOnDelete();

            $table->enum('provider', [
                'card',
                'instapay',
                'fawry',
                'paymob',
            ]);

            $table->enum('type', [
                'authorization',
                'capture',
                'instant_pay',
                'refund',
                'void',
            ]);

            $table->string('provider_transaction_id')->nullable();

            $table->string('provider_order_id')->nullable();

            $table->decimal('amount', 12, 2);

            $table->enum('status', [
                'pending',
                'success',
                'failed',
            ])->default('pending');

            $table->json('response_payload')->nullable();

            $table->timestamp('authorized_at')->nullable();

            $table->timestamp('captured_at')->nullable();

            $table->timestamp('failed_at')->nullable();

            $table->timestamp('authorization_expires_at')->nullable();

            $table->timestamps();

            $table->index('payment_session_tenant_id');

            $table->index('provider_transaction_id');

            $table->index('status');

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
