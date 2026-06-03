<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// All tenant contracts
$contracts = DB::table('tenant_contracts')
    ->join('users', 'tenant_contracts.user_id', '=', 'users.id')
    ->select('tenant_contracts.*', 'users.email')
    ->get();

echo "=== All Tenant Contracts ===\n";
echo json_encode($contracts, JSON_PRETTY_PRINT) . "\n\n";

// Ahmad's membership
$userId = DB::table('users')->where('email','ahmad@gmail.com')->value('id');
$memberships = DB::table('apartment_members')->where('user_id', $userId)->get();
echo "=== Ahmad's Memberships ===\n";
echo json_encode($memberships, JSON_PRETTY_PRINT) . "\n";
