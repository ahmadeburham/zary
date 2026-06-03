<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('reviewer_id');
            $table->uuid('reviewee_id');
            $table->uuid('apartment_id')->nullable();
            $table->uuid('contract_id')->nullable();
            $table->enum('type', ['user', 'apartment', 'contract'])->default('apartment');
            $table->tinyInteger('rating')->unsigned()->comment('1-5 stars');
            $table->text('comment')->nullable();
            $table->json('categories')->nullable()->comment('JSON with category ratings: cleanliness, communication, etc.');
            $table->enum('status', ['pending', 'approved', 'rejected', 'hidden'])->default('approved');
            $table->boolean('is_anonymous')->default(false);
            $table->timestamp('moderated_at')->nullable();
            $table->uuid('moderated_by')->nullable();
            $table->text('moderation_note')->nullable();
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('reviewer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewee_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('apartment_id')->references('id')->on('apartments')->onDelete('cascade');
            $table->foreign('moderated_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index(['reviewee_id', 'type', 'status']);
            $table->index(['apartment_id', 'status']);
            $table->index(['status', 'created_at']);
        });
        
        // Add average rating to apartments
        Schema::table('apartments', function (Blueprint $table) {
            $table->decimal('avg_rating', 2, 1)->default(0)->after('status');
            $table->integer('total_reviews')->default(0)->after('avg_rating');
        });
    }

    public function down(): void
    {
        Schema::table('apartments', function (Blueprint $table) {
            $table->dropColumn(['avg_rating', 'total_reviews']);
        });
        
        Schema::dropIfExists('reviews');
    }
};
