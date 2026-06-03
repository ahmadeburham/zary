<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('apartment_id');
            $table->uuid('owner_id');
            $table->uuid('tenant_id');
            $table->uuid('contract_id')->nullable();
            
            $table->enum('status', [
                'draft',
                'pending_signatures',
                'active',
                'expiring_soon',
                'expired',
                'terminated',
                'renewed'
            ])->default('draft');
            
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('monthly_rent', 10, 2);
            $table->decimal('security_deposit', 10, 2)->default(0);
            $table->enum('rent_frequency', ['monthly', 'quarterly', 'yearly'])->default('monthly');
            
            $table->text('terms')->nullable();
            $table->json('special_conditions')->nullable();
            
            $table->timestamp('signed_by_owner_at')->nullable();
            $table->timestamp('signed_by_tenant_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            
            $table->boolean('auto_renew')->default(false);
            $table->date('renewal_notice_date')->nullable();
            $table->text('termination_reason')->nullable();
            $table->timestamp('terminated_at')->nullable();
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('apartment_id')->references('id')->on('apartments')->onDelete('cascade');
            $table->foreign('owner_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes
            $table->index(['status', 'end_date']);
            $table->index(['owner_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['apartment_id', 'status']);
            $table->index('created_at');
        });
        
        // Lease payment tracking
        Schema::create('lease_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('lease_id');
            $table->uuid('payment_order_id')->nullable();
            
            $table->date('due_date');
            $table->date('paid_date')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('late_fee', 10, 2)->default(0);
            
            $table->enum('status', ['pending', 'paid', 'overdue', 'waived'])->default('pending');
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            $table->foreign('lease_id')->references('id')->on('leases')->onDelete('cascade');
            $table->index(['lease_id', 'due_date']);
            $table->index(['lease_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_payments');
        Schema::dropIfExists('leases');
    }
};
