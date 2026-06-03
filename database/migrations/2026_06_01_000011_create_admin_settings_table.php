<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->enum('type', ['string', 'integer', 'boolean', 'json', 'array'])->default('string');
            $table->string('group')->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->timestamps();
            
            $table->index(['group', 'key']);
        });
        
        // Insert default settings
        DB::table('admin_settings')->insert([
            // General
            ['key' => 'app_name', 'value' => 'Sukoon', 'type' => 'string', 'group' => 'general', 'description' => 'Application name', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'app_logo', 'value' => '', 'type' => 'string', 'group' => 'general', 'description' => 'App logo URL', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'support_email', 'value' => 'support@sukoon.com', 'type' => 'string', 'group' => 'general', 'description' => 'Support email address', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'support_phone', 'value' => '+201000000000', 'type' => 'string', 'group' => 'general', 'description' => 'Support phone number', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            
            // Payment
            ['key' => 'payment_currency', 'value' => 'EGP', 'type' => 'string', 'group' => 'payment', 'description' => 'Default currency', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'payment_enabled', 'value' => 'true', 'type' => 'boolean', 'group' => 'payment', 'description' => 'Enable payments', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'service_fee_percent', 'value' => '2.5', 'type' => 'string', 'group' => 'payment', 'description' => 'Platform service fee percentage', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'min_deposit_amount', 'value' => '1000', 'type' => 'integer', 'group' => 'payment', 'description' => 'Minimum security deposit', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            
            // Verification
            ['key' => 'id_verification_required', 'value' => 'true', 'type' => 'boolean', 'group' => 'verification', 'description' => 'Require ID verification', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'auto_approve_verified', 'value' => 'false', 'type' => 'boolean', 'group' => 'verification', 'description' => 'Auto-approve verified users', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            
            // Notifications
            ['key' => 'email_notifications', 'value' => 'true', 'type' => 'boolean', 'group' => 'notifications', 'description' => 'Enable email notifications', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'sms_notifications', 'value' => 'true', 'type' => 'boolean', 'group' => 'notifications', 'description' => 'Enable SMS notifications', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'push_notifications', 'value' => 'true', 'type' => 'boolean', 'group' => 'notifications', 'description' => 'Enable push notifications', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            
            // Security
            ['key' => 'max_login_attempts', 'value' => '5', 'type' => 'integer', 'group' => 'security', 'description' => 'Max failed login attempts', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'session_timeout', 'value' => '60', 'type' => 'integer', 'group' => 'security', 'description' => 'Session timeout in minutes', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'require_strong_password', 'value' => 'true', 'type' => 'boolean', 'group' => 'security', 'description' => 'Require strong passwords', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            
            // Maintenance
            ['key' => 'maintenance_mode', 'value' => 'false', 'type' => 'boolean', 'group' => 'maintenance', 'description' => 'Maintenance mode', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'maintenance_message', 'value' => 'We are currently performing maintenance. Please check back later.', 'type' => 'string', 'group' => 'maintenance', 'description' => 'Maintenance message', 'is_editable' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_settings');
    }
};
