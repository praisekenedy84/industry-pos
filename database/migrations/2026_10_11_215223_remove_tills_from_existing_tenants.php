<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $migration = require database_path('tenant/2026_10_11_215222_remove_tills_from_tenant_schema.php');
        foreach (DB::table('tenants')->pluck('schema_name') as $schemaName) {
            $migration->removeFromSchema($schemaName);
        }
        DB::table('features')->where('key', 'module.pos')->update([
            'description' => 'Sales, payments, receipts and returns.',
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Till removal cannot be reversed because its historical session data has been removed.');
    }
};
