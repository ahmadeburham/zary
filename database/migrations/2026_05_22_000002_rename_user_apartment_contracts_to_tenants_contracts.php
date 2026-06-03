<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::rename('user_apartment_contracts', 'tenants_contracts');
    }

    public function down(): void
    {
        Schema::rename('tenants_contracts', 'user_apartment_contracts');
    }
};
