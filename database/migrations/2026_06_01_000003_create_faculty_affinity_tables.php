<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Faculty to Affinity Group mapping
        Schema::create('faculty_affinity_groups', function (Blueprint $table) {
            $table->string('faculty_name')->primary();
            $table->enum('affinity_group', ['STEM_MEDICAL', 'BUSINESS', 'HUMANITIES', 'UNKNOWN']);
        });

        // Affinity similarity matrix for recommender
        Schema::create('affinity_similarity_matrix', function (Blueprint $table) {
            $table->string('source_group');
            $table->string('target_group');
            $table->float('similarity_score');
            $table->primary(['source_group', 'target_group']);
        });

        // Seed faculty affinity groups
        DB::table('faculty_affinity_groups')->insert([
            ['faculty_name' => 'Engineering', 'affinity_group' => 'STEM_MEDICAL'],
            ['faculty_name' => 'Medicine', 'affinity_group' => 'STEM_MEDICAL'],
            ['faculty_name' => 'Pharmacy', 'affinity_group' => 'STEM_MEDICAL'],
            ['faculty_name' => 'Computer Science', 'affinity_group' => 'STEM_MEDICAL'],
            ['faculty_name' => 'Business Administration', 'affinity_group' => 'BUSINESS'],
            ['faculty_name' => 'Accounting', 'affinity_group' => 'BUSINESS'],
            ['faculty_name' => 'Economics', 'affinity_group' => 'BUSINESS'],
            ['faculty_name' => 'Arts', 'affinity_group' => 'HUMANITIES'],
            ['faculty_name' => 'Literature', 'affinity_group' => 'HUMANITIES'],
            ['faculty_name' => 'Law', 'affinity_group' => 'HUMANITIES'],
            ['faculty_name' => 'Education', 'affinity_group' => 'HUMANITIES'],
        ]);

        // Seed affinity similarity matrix
        DB::table('affinity_similarity_matrix')->insert([
            ['source_group' => 'STEM_MEDICAL', 'target_group' => 'STEM_MEDICAL', 'similarity_score' => 1.0],
            ['source_group' => 'STEM_MEDICAL', 'target_group' => 'BUSINESS', 'similarity_score' => 0.6],
            ['source_group' => 'STEM_MEDICAL', 'target_group' => 'HUMANITIES', 'similarity_score' => 0.5],
            ['source_group' => 'BUSINESS', 'target_group' => 'STEM_MEDICAL', 'similarity_score' => 0.6],
            ['source_group' => 'BUSINESS', 'target_group' => 'BUSINESS', 'similarity_score' => 1.0],
            ['source_group' => 'BUSINESS', 'target_group' => 'HUMANITIES', 'similarity_score' => 0.7],
            ['source_group' => 'HUMANITIES', 'target_group' => 'STEM_MEDICAL', 'similarity_score' => 0.5],
            ['source_group' => 'HUMANITIES', 'target_group' => 'BUSINESS', 'similarity_score' => 0.7],
            ['source_group' => 'HUMANITIES', 'target_group' => 'HUMANITIES', 'similarity_score' => 1.0],
            ['source_group' => 'UNKNOWN', 'target_group' => 'STEM_MEDICAL', 'similarity_score' => 0.5],
            ['source_group' => 'UNKNOWN', 'target_group' => 'BUSINESS', 'similarity_score' => 0.5],
            ['source_group' => 'UNKNOWN', 'target_group' => 'HUMANITIES', 'similarity_score' => 0.5],
            ['source_group' => 'UNKNOWN', 'target_group' => 'UNKNOWN', 'similarity_score' => 0.5],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_affinity_groups');
        Schema::dropIfExists('affinity_similarity_matrix');
    }
};
