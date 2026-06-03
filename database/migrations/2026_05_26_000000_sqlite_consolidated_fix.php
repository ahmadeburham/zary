<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated SQLite-compatible migration.
 * Adds all columns/tables that MySQL-only MODIFY COLUMN migrations cannot add on SQLite.
 * Runs AFTER all base create_* migrations succeed.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── apartments: missing columns ──────────────────────────────────────
        Schema::table('apartments', function (Blueprint $table) {
            if (!Schema::hasColumn('apartments', 'rent_duration')) {
                $table->unsignedInteger('rent_duration')->default(1);
            }
            if (!Schema::hasColumn('apartments', 'rented_at')) {
                $table->timestamp('rented_at')->nullable();
            }
            if (!Schema::hasColumn('apartments', 'latitude')) {
                $table->decimal('latitude', 10, 8)->nullable();
            }
            if (!Schema::hasColumn('apartments', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable();
            }
        });

        // ── users: missing columns ────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_verified')) {
                $table->boolean('is_verified')->default(false);
            }
            if (!Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'remember_token')) {
                $table->rememberToken();
            }
            if (!Schema::hasColumn('users', 'name')) {
                $table->string('name')->nullable();
            }
            if (!Schema::hasColumn('users', 'payout_type')) {
                $table->string('payout_type')->nullable();
            }
            if (!Schema::hasColumn('users', 'payout_number')) {
                $table->string('payout_number')->nullable();
            }
            if (!Schema::hasColumn('users', 'has_paid_platform_fee')) {
                $table->boolean('has_paid_platform_fee')->default(false);
            }
            if (!Schema::hasColumn('users', 'payout_info')) {
                $table->text('payout_info')->nullable();
            }
            if (!Schema::hasColumn('users', 'fcm_token')) {
                $table->string('fcm_token')->nullable();
            }
            if (!Schema::hasColumn('users', 'onboarding_screen')) {
                $table->integer('onboarding_screen')->default(0);
            }
            if (!Schema::hasColumn('users', 'is_profile_completed')) {
                $table->boolean('is_profile_completed')->default(false);
            }
            if (!Schema::hasColumn('users', 'facebook_id')) {
                $table->string('facebook_id')->nullable();
            }
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable();
            }
            if (!Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // ── apartment_members: missing columns ────────────────────────────────
        Schema::table('apartment_members', function (Blueprint $table) {
            if (!Schema::hasColumn('apartment_members', 'payment_deadline')) {
                $table->timestamp('payment_deadline')->nullable();
            }
        });

        // ── identity_documents: missing columns ───────────────────────────────
        Schema::table('identity_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('identity_documents', 'status')) {
                $table->string('status')->default('pending');
            }
            if (!Schema::hasColumn('identity_documents', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });

        // ── apartment_documents: missing columns ──────────────────────────────
        Schema::table('apartment_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('apartment_documents', 'status')) {
                $table->string('status')->default('pending');
            }
            if (!Schema::hasColumn('apartment_documents', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });

        // ── sponsor_profiles table ────────────────────────────────────────────
        if (!Schema::hasTable('sponsor_profiles')) {
            Schema::create('sponsor_profiles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
                $table->string('company_name');
                $table->text('company_details')->nullable();
                $table->string('target_audience')->nullable();
                $table->timestamps();
            });
        }

        // ── payment_orders table ──────────────────────────────────────────────
        if (!Schema::hasTable('payment_orders')) {
            Schema::create('payment_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('idempotency_key')->nullable()->unique();
                $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignUuid('apartment_id')->constrained()->cascadeOnDelete();
                $table->uuid('rent_cycle_id')->nullable();
                $table->string('paymob_order_id')->nullable()->index();
                $table->string('paymob_payment_key')->nullable();
                $table->string('payment_url')->nullable();
                $table->unsignedBigInteger('amount_cents')->default(0);
                $table->json('breakdown')->nullable();
                $table->string('status')->default('pending');
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        // ── transactions table ────────────────────────────────────────────────
        if (!Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('payment_order_id')->nullable();
                $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignUuid('apartment_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type');
                $table->string('direction')->default('in');
                $table->unsignedBigInteger('amount_cents')->default(0);
                $table->string('currency')->default('EGP');
                $table->string('paymob_transaction_id')->nullable()->unique();
                $table->string('status')->default('pending');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        // ── refund_requests table ─────────────────────────────────────────────
        if (!Schema::hasTable('refund_requests')) {
            Schema::create('refund_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('payment_order_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
                $table->text('reason')->nullable();
                $table->string('status')->default('pending');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        // ── rent_cycles table ─────────────────────────────────────────────────
        if (!Schema::hasTable('rent_cycles')) {
            Schema::create('rent_cycles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('apartment_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('cycle_number')->default(1);
                $table->date('cycle_start')->nullable();
                $table->date('cycle_end')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->string('status')->default('pending');
                $table->timestamps();
            });
        }

        // ── insurance_payments table ──────────────────────────────────────────
        if (!Schema::hasTable('insurance_payments')) {
            Schema::create('insurance_payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('apartment_id')->constrained()->cascadeOnDelete();
                $table->uuid('payment_order_id')->nullable();
                $table->unsignedBigInteger('amount_cents')->default(0);
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        // ── idempotency_keys table ────────────────────────────────────────────
        if (!Schema::hasTable('idempotency_keys')) {
            Schema::create('idempotency_keys', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('key')->unique();
                $table->json('response')->nullable();
                $table->timestamps();
            });
        }

        // ── tenants_contracts table ───────────────────────────────────────────
        if (!Schema::hasTable('tenants_contracts')) {
            Schema::create('tenants_contracts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('apartment_id')->constrained()->cascadeOnDelete();
                $table->string('path');
                $table->string('type')->default('contract');
                $table->string('status')->default('pending');
                $table->text('rejection_reason')->nullable();
                $table->timestamps();
            });
        }

        // ── cache tables ──────────────────────────────────────────────────────
        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }
        if (!Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('tenants_contracts');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('insurance_payments');
        Schema::dropIfExists('rent_cycles');
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_orders');
        Schema::dropIfExists('sponsor_profiles');
    }
};
