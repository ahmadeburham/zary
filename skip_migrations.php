<?php
/**
 * Marks MySQL-only migrations as already run so artisan migrate skips them.
 * Run ONCE after migrate:fresh fails at the first MySQL migration.
 * Usage: php skip_migrations.php
 */

$dbPath = __DIR__ . '/database/database.sqlite';
$pdo = new PDO("sqlite:$dbPath");

$toSkip = [
    '2026_05_16_161617_alter_status_column_in_apartments_table',
    '2026_05_16_174542_create_payment_sessions_table',
    '2026_05_16_175114_create_payment_session_tenants_table',
    '2026_05_16_175827_create_payment_transactions_table',
    '2026_05_16_180523_create_tenant_insurances_table',
    '2026_05_17_010932_create_idempotency_keys_table',
    '2026_05_17_214352_add_facebook_id_to_users_table',
    '2026_05_18_101144_user_apartment_contracts',
    '2026_05_18_122252_add_status_to_user_apartment_contracts_table',
    '2026_05_21_000000_align_apartments_schema',
    '2026_05_21_180000_add_soft_deletes_to_users_table',
    '2026_05_22_000000_add_refused_to_apartment_status_enums',
    '2026_05_22_000001_add_pending_to_apartment_members_status_enum',
    '2026_05_22_000002_rename_user_apartment_contracts_to_tenants_contracts',
    '2026_05_22_100000_payment_schema_phase1',
    '2026_05_22_100001_create_payment_tables',
    '2026_05_22_121254_create_cache_table',
    '2026_05_22_152524_add_fcm_token_to_users_table',
    '2026_05_22_160000_add_onboarding_fields_to_users_table',
    '2026_05_22_160001_create_sponsor_profiles_table',
    '2026_05_23_000000_add_payout_and_document_status',
    '2026_05_24_173404_add_closed_uploading_contracts_to_apartments_status',
    '2026_05_24_204609_make_user_id_nullable_in_transactions_table',
];

$stmt = $pdo->query("SELECT COALESCE(MAX(batch), 0) FROM migrations");
$maxBatch = (int) $stmt->fetchColumn();
$batch = $maxBatch + 1;

$insert = $pdo->prepare("INSERT OR IGNORE INTO migrations (migration, batch) VALUES (?, ?)");

foreach ($toSkip as $migration) {
    $insert->execute([$migration, $batch]);
    echo "Skipped: $migration\n";
}

echo "\nDone. Marked " . count($toSkip) . " migrations as run (batch $batch).\n";
