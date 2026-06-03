<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds OCR-extracted ID fields to user_profiles and liveness/face-match
 * result flags to users so verification results survive server restarts.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── user_profiles: OCR text fields ────────────────────────────────────
        Schema::table('user_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('user_profiles', 'name')) {
                $table->string('name')->nullable();
            }
            if (!Schema::hasColumn('user_profiles', 'id_number')) {
                $table->string('id_number')->nullable();
            }
            if (!Schema::hasColumn('user_profiles', 'birth_date')) {
                $table->string('birth_date')->nullable();
            }
            if (!Schema::hasColumn('user_profiles', 'profession')) {
                $table->string('profession')->nullable();
            }
            if (!Schema::hasColumn('user_profiles', 'religion')) {
                $table->string('religion')->nullable();
            }
            if (!Schema::hasColumn('user_profiles', 'marital_status')) {
                $table->string('marital_status')->nullable();
            }
            if (!Schema::hasColumn('user_profiles', 'id_expiry_date')) {
                $table->string('id_expiry_date')->nullable();
            }
            if (!Schema::hasColumn('user_profiles', 'id_issue_date')) {
                $table->string('id_issue_date')->nullable();
            }
            if (!Schema::hasColumn('user_profiles', 'address')) {
                $table->string('address')->nullable();
            }
        });

        // ── users: liveness + face-match result flags ─────────────────────────
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'liveness_passed')) {
                $table->boolean('liveness_passed')->default(false);
            }
            if (!Schema::hasColumn('users', 'face_match_passed')) {
                $table->boolean('face_match_passed')->default(false);
            }
        });

        // ── apartment_members: fix SQLite enum to allow 'pending' ─────────────
        // SQLite doesn't support ALTER COLUMN; recreate check via string column.
        // The original migration used enum(['active','cancelled']) which SQLite
        // enforces via a CHECK constraint — 'pending' was never included.
        // We drop and recreate the column as a plain string so all values work.
        if (DB::getDriverName() === 'sqlite') {
            // Each step is guarded so re-running after partial failure is safe.

            // Step 1: drop the composite index (only if it still exists)
            $indexes = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='apartment_members'");
            $indexNames = array_column($indexes, 'name');
            if (in_array('apartment_members_apartment_id_membership_status_index', $indexNames)) {
                Schema::table('apartment_members', function (Blueprint $table) {
                    $table->dropIndex('apartment_members_apartment_id_membership_status_index');
                });
            }

            // Step 2: add temp column (only if not already there)
            if (!Schema::hasColumn('apartment_members', 'membership_status_new')) {
                Schema::table('apartment_members', function (Blueprint $table) {
                    $table->string('membership_status_new')->default('pending');
                });
            }

            // Step 3: copy data into temp column
            if (Schema::hasColumn('apartment_members', 'membership_status')) {
                DB::statement('UPDATE apartment_members SET membership_status_new = membership_status');
            }

            // Step 4: drop old enum column
            if (Schema::hasColumn('apartment_members', 'membership_status')) {
                Schema::table('apartment_members', function (Blueprint $table) {
                    $table->dropColumn('membership_status');
                });
            }

            // Step 5: rename temp column to final name
            if (Schema::hasColumn('apartment_members', 'membership_status_new')) {
                Schema::table('apartment_members', function (Blueprint $table) {
                    $table->renameColumn('membership_status_new', 'membership_status');
                });
            }

            // Step 6: recreate index
            $indexes2 = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='apartment_members'");
            $indexNames2 = array_column($indexes2, 'name');
            if (!in_array('apartment_members_apartment_id_membership_status_index', $indexNames2)) {
                Schema::table('apartment_members', function (Blueprint $table) {
                    $table->index(['apartment_id', 'membership_status']);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('user_profiles', 'name')            ? 'name'             : null,
                Schema::hasColumn('user_profiles', 'id_number')       ? 'id_number'       : null,
                Schema::hasColumn('user_profiles', 'birth_date')      ? 'birth_date'       : null,
                Schema::hasColumn('user_profiles', 'profession')      ? 'profession'       : null,
                Schema::hasColumn('user_profiles', 'religion')        ? 'religion'         : null,
                Schema::hasColumn('user_profiles', 'marital_status')  ? 'marital_status'   : null,
                Schema::hasColumn('user_profiles', 'id_expiry_date')  ? 'id_expiry_date'   : null,
                Schema::hasColumn('user_profiles', 'id_issue_date')   ? 'id_issue_date'    : null,
                Schema::hasColumn('user_profiles', 'address')         ? 'address'          : null,
            ]));
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('users', 'liveness_passed')   ? 'liveness_passed'   : null,
                Schema::hasColumn('users', 'face_match_passed') ? 'face_match_passed' : null,
            ]));
        });
    }
};
