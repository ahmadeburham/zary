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
        // Add foreign key constraint for faculty
        Schema::table('student_details', function (Blueprint $table) {
            $table->foreign('faculty')
                ->references('faculty_name')
                ->on('faculty_affinity_groups')
                ->onDelete('restrict');
        });

        // Change location to enum for validation
        Schema::table('student_details', function (Blueprint $table) {
            $table->dropColumn('preferred_location');
        });

        Schema::table('student_details', function (Blueprint $table) {
            $table->enum('preferred_location', [
                'Cairo', 'Giza', 'Alexandria', 'Mansoura', 'Tanta',
                'Port Said', 'Suez', 'Ismailia', 'Zagazig', 'Other'
            ])->nullable()->after('budget_max');
        });

        // Add check constraint for budget (SQLite doesn't support, but MySQL does)
        // For SQLite compatibility, validation happens in application layer
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_details', function (Blueprint $table) {
            $table->dropForeign(['faculty']);
        });

        Schema::table('student_details', function (Blueprint $table) {
            $table->dropColumn('preferred_location');
        });

        Schema::table('student_details', function (Blueprint $table) {
            $table->string('preferred_location')->nullable()->after('budget_max');
        });
    }
};
