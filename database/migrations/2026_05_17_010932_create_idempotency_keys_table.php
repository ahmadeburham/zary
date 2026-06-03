<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {

            $table->uuid('id')->primary();

            /**
             * Stable business operation key
             *
             * Examples:
             * capture:55
             * refund:91
             * webhook:paymob:evt_991
             * session:15:2026:5
             */
            $table->string('key')->unique();

            /**
             * Operation name
             *
             * Examples:
             * capture_payment
             * refund_payment
             * process_webhook
             * create_monthly_session
             */
            $table->string('operation');

            /**
             * Optional associated entity
             */
            $table->string('resource_type')->nullable();

            $table->string('resource_id')->nullable();

            /**
             * processing
             * completed
             * failed
             */
            $table->enum('status', [
                'processing',
                'completed',
                'failed',
            ])->default('processing');

            /**
             * Optional request payload hash
             */
            $table->string('request_hash')->nullable();

            /**
             * Optional cached response
             */
            $table->json('response')->nullable();

            /**
             * Error message if failed
             */
            $table->text('error_message')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('operation');

            $table->index([
                'resource_type',
                'resource_id',
            ]);

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
