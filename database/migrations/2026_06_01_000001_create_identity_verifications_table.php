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
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_id')->unique();
            $table->enum('overall_status', ['pending', 'completed', 'failed'])->default('pending');

            // Individual Check Results (for audit/debugging)
            $table->boolean('validation_passed')->default(false);
            $table->boolean('face_match_passed')->default(false);
            $table->boolean('liveness_passed')->default(false);
            $table->boolean('ocr_front_passed')->default(false);
            $table->boolean('ocr_back_passed')->default(false);

            // Extracted Data (stored even if verification fails, for retry analysis)
            $table->string('id_number')->nullable();
            $table->string('extracted_name')->nullable();
            $table->string('birth_date')->nullable();
            $table->string('address')->nullable();

            // Full ML Response (JSON)
            $table->json('ml_result_json');

            // Processing Metadata
            $table->timestamp('submitted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'submitted_at']);
            $table->index('request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
