<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_details', function (Blueprint $table) {
            $table->decimal('university_latitude', 10, 8)->nullable()->after('university');
            $table->decimal('university_longitude', 11, 8)->nullable()->after('university_latitude');
            $table->string('faculty', 255)->nullable()->change();
            $table->string('major_category', 50)->nullable()->after('major');
            $table->index(['university_latitude', 'university_longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('student_details', function (Blueprint $table) {
            $table->dropColumn(['university_latitude', 'university_longitude', 'major_category']);
        });
    }
};
