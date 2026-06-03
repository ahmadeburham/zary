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
        Schema::table('student_details', function (Blueprint $table) {
            // Budget preferences (nullable - optional for recommender)
            $table->decimal('budget_min', 12, 2)->nullable()->after('faculty');
            $table->decimal('budget_max', 12, 2)->nullable()->after('budget_min');

            // Location preference
            $table->string('preferred_location')->nullable()->after('budget_max');

            // Furnished preference
            $table->boolean('prefers_furnished')->nullable()->after('preferred_location');

            // Major (more specific than faculty)
            $table->string('major')->nullable()->after('prefers_furnished');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_details', function (Blueprint $table) {
            $table->dropColumn(['budget_min', 'budget_max', 'preferred_location', 'prefers_furnished', 'major']);
        });
    }
};
