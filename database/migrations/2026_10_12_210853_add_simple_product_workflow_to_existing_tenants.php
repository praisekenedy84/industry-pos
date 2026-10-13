<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $migration = require database_path('tenant/2026_10_12_210852_add_simple_product_workflow_to_tenant_schema.php');
        foreach (DB::table('tenants')->pluck('schema_name') as $schemaName) {
            $migration->addToSchema($schemaName);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Receipt and costing history must be retained. Use a forward migration.');
    }
};
