<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $migration = require database_path('tenant/2026_10_11_220914_add_production_lines_to_tenant_schema.php');
        foreach (DB::table('tenants')->pluck('schema_name') as $schemaName) {
            $migration->addToSchema($schemaName);
        }
    }

    public function down(): void
    {
        $migration = require database_path('tenant/2026_10_11_220914_add_production_lines_to_tenant_schema.php');
        foreach (DB::table('tenants')->pluck('schema_name') as $schemaName) {
            $migration->removeFromSchema($schemaName);
        }
    }
};
