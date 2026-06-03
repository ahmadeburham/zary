<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reporter_id');
            $table->uuid('reported_id')->nullable();
            $table->uuid('apartment_id')->nullable();
            $table->uuid('contract_id')->nullable();
            $table->uuid('payment_order_id')->nullable();
            
            $table->enum('type', [
                'payment_issue',
                'contract_breach',
                'apartment_condition',
                'fraud',
                'harassment',
                'other'
            ]);
            
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', [
                'open',
                'under_review',
                'escalated',
                'resolved',
                'closed',
                'rejected'
            ])->default('open');
            
            $table->text('description');
            $table->json('evidence')->nullable()->comment('JSON array of file URLs');
            
            $table->uuid('assigned_to')->nullable();
            $table->text('resolution')->nullable();
            $table->json('resolution_details')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('reporter_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reported_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('apartment_id')->references('id')->on('apartments')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index(['status', 'priority']);
            $table->index(['assigned_to', 'status']);
            $table->index(['type', 'status']);
            $table->index('created_at');
        });
        
        // Create dispute comments table for communication
        Schema::create('dispute_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('dispute_id');
            $table->uuid('user_id');
            $table->text('comment');
            $table->boolean('is_internal')->default(false)->comment('Internal admin notes vs visible to parties');
            $table->timestamps();
            
            $table->foreign('dispute_id')->references('id')->on('disputes')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['dispute_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_comments');
        Schema::dropIfExists('disputes');
    }
};
