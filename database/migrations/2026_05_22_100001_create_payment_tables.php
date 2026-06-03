<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rent_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('apartment_id')->constrained('apartments')->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle_number')->default(1);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['pending_payment', 'active', 'completed', 'cancelled'])->default('pending_payment');
            $table->timestamps();

            $table->unique(['apartment_id', 'cycle_number']);
        });

        Schema::create('payment_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key')->unique();
            $table->foreignUuid('rent_cycle_id')->constrained('rent_cycles')->cascadeOnDelete();
            $table->foreignUuid('apartment_id')->constrained('apartments')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');            // total to pay
            $table->json('breakdown');                          // {rent_cents, insurance_cents, platform_fee_cents}
            $table->string('paymob_order_id')->nullable()->unique();
            $table->text('paymob_payment_key')->nullable();
            $table->text('payment_url')->nullable();
            $table->enum('status', ['pending', 'paid', 'refunded', 'expired', 'failed'])->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('expires_at');                    // now + 24h
            $table->timestamps();

            $table->index(['rent_cycle_id', 'user_id']);
            $table->index(['apartment_id', 'status']);
        });

        Schema::create('insurance_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('apartment_id')->constrained('apartments')->cascadeOnDelete();
            $table->foreignUuid('payment_order_id')->constrained('payment_orders')->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->dateTime('paid_at');
            $table->timestamps();

            $table->unique(['user_id', 'apartment_id']);    // one insurance record per tenant per apartment
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_order_id')->nullable()->constrained('payment_orders')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('apartment_id')->constrained('apartments')->cascadeOnDelete();
            $table->enum('type', ['charge', 'refund', 'payout_owner', 'payout_platform']);
            $table->enum('direction', ['in', 'out']);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EGP');
            $table->string('paymob_transaction_id')->nullable()->unique();
            $table->enum('status', ['success', 'failed', 'pending'])->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['apartment_id', 'type']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_order_id')->constrained('payment_orders')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('insurance_payments');
        Schema::dropIfExists('payment_orders');
        Schema::dropIfExists('rent_cycles');
    }
};
