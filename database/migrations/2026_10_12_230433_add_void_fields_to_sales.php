<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $migration = require database_path('tenant/2026_10_12_230432_add_void_fields_to_sales.php');
        foreach (DB::table('tenants')->pluck('schema_name') as $schemaName) {
            $migration->addToSchema($schemaName);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Voided sales must stay in the audit history. Use a forward migration.');
    }
};
