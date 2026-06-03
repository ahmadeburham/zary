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
        Schema::create('apartments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->decimal('insurance', 10, 2);
            $table->integer('capacity');

            // New Columns
            $table->integer('male_count')->default(0);
            $table->integer('female_count')->default(0);

            $table->enum('gender_allowed', ['male', 'female', 'any']);
            $table->integer('rooms_count');
            $table->integer('beds_count');
            $table->boolean('has_ac')->default(false);
            $table->boolean('has_water')->default(false);
            $table->boolean('has_gas')->default(false);
            $table->boolean('is_furnished')->default(false);
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->enum('status', ['draft', 'open', 'closed', 'full'])->default('draft');
            $table->enum('verification_status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending');
            $table->timestamps();

            // Raw SQL Check Constraint: Sum of counts cannot exceed capacity
            // Note: Check constraints require MySQL 8.0.16+ or MariaDB 10.2.1+
            // DB::statement('ALTER TABLE apartments ADD CONSTRAINT check_capacity_limit CHECK ((male_count + female_count) <= capacity)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apartments');
    }
};
