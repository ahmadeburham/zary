<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('universities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('city', 64);
            $table->enum('type', ['university', 'institute', 'college', 'academy'])->default('university');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['city', 'is_active']);
        });

        Schema::create('university_programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('university_id')->constrained('universities')->cascadeOnDelete();
            $table->string('name');
            $table->enum('affinity_group', ['STEM_MEDICAL', 'BUSINESS', 'HUMANITIES', 'UNKNOWN'])->default('UNKNOWN');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['university_id', 'name']);
        });

        Schema::table('student_details', function (Blueprint $table) {
            if (!Schema::hasColumn('student_details', 'university_id')) {
                $table->foreignUuid('university_id')->nullable()->after('rental_profile_id')
                    ->constrained('universities')->nullOnDelete();
            }
            if (!Schema::hasColumn('student_details', 'university_program_id')) {
                $table->foreignUuid('university_program_id')->nullable()->after('university_id')
                    ->constrained('university_programs')->nullOnDelete();
            }
        });

        Schema::table('identity_verifications', function (Blueprint $table) {
            if (!Schema::hasColumn('identity_verifications', 'front_image_path')) {
                $table->string('front_image_path')->nullable()->after('ml_result_json');
            }
            if (!Schema::hasColumn('identity_verifications', 'back_image_path')) {
                $table->string('back_image_path')->nullable()->after('front_image_path');
            }
            if (!Schema::hasColumn('identity_verifications', 'selfie_image_path')) {
                $table->string('selfie_image_path')->nullable()->after('back_image_path');
            }
            if (!Schema::hasColumn('identity_verifications', 'admin_review_status')) {
                $table->string('admin_review_status', 32)->nullable()->after('overall_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            foreach (['front_image_path', 'back_image_path', 'selfie_image_path', 'admin_review_status'] as $col) {
                if (Schema::hasColumn('identity_verifications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('student_details', function (Blueprint $table) {
            if (Schema::hasColumn('student_details', 'university_program_id')) {
                $table->dropConstrainedForeignId('university_program_id');
            }
            if (Schema::hasColumn('student_details', 'university_id')) {
                $table->dropConstrainedForeignId('university_id');
            }
        });

        Schema::dropIfExists('university_programs');
        Schema::dropIfExists('universities');
    }
};
