<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $migration = require database_path('tenant/2026_10_12_192436_add_unit_cost_to_recipe_lines.php');
        foreach (DB::table('tenants')->pluck('schema_name') as $schemaName) {
            $migration->addToSchema($schemaName);
        }
    }

    public function down(): void
    {
        $migration = require database_path('tenant/2026_10_12_192436_add_unit_cost_to_recipe_lines.php');
        foreach (DB::table('tenants')->pluck('schema_name') as $schemaName) {
            $migration->removeFromSchema($schemaName);
        }
    }
};
