<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('display_name');
            
            $table->enum('type', ['card', 'wallet', 'bank_transfer', 'cash', 'crypto']);
            $table->enum('status', ['active', 'inactive', 'maintenance', 'deprecated'])->default('inactive');
            
            $table->json('config')->nullable()->comment('API keys, endpoints, etc.');
            $table->json('supported_currencies')->nullable();
            $table->decimal('transaction_fee_percent', 5, 2)->default(0);
            $table->decimal('transaction_fee_fixed', 10, 2)->default(0);
            $table->decimal('min_amount', 10, 2)->default(0);
            $table->decimal('max_amount', 10, 2)->nullable();
            
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();
            
            $table->timestamps();
            
            $table->index(['status', 'type']);
            $table->index('is_default');
        });
        
        // Insert default providers
        DB::table('payment_providers')->insert([
            [
                'name' => 'Paymob',
                'code' => 'paymob',
                'display_name' => 'Credit/Debit Card',
                'type' => 'card',
                'status' => 'active',
                'is_default' => true,
                'sort_order' => 1,
                'supported_currencies' => json_encode(['EGP', 'USD']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Paymob Wallet',
                'code' => 'paymob_wallet',
                'display_name' => 'Mobile Wallet',
                'type' => 'wallet',
                'status' => 'active',
                'is_default' => false,
                'sort_order' => 2,
                'supported_currencies' => json_encode(['EGP']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'InstaPay',
                'code' => 'instapay',
                'display_name' => 'InstaPay',
                'type' => 'bank_transfer',
                'status' => 'active',
                'is_default' => false,
                'sort_order' => 3,
                'supported_currencies' => json_encode(['EGP']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Cash',
                'code' => 'cash',
                'display_name' => 'Cash Payment',
                'type' => 'cash',
                'status' => 'active',
                'is_default' => false,
                'sort_order' => 4,
                'supported_currencies' => json_encode(['EGP', 'USD']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
