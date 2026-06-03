<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_insurances', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignUuid('apartment_id')
                ->constrained('apartments')
                ->cascadeOnDelete();

            $table->foreignUuid('payment_transaction_id')
                ->nullable()
                ->constrained('payment_transactions')
                ->nullOnDelete();

            $table->decimal('amount', 12, 2);

            $table->enum('status', [
                'pending',
                'paid',
                'refunded',
            ])->default('pending');

            $table->timestamp('paid_at')->nullable();

            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->index([
                'user_id',
                'apartment_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_insurances');
    }
};
