<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $migration = require database_path('tenant/2026_10_11_222422_add_expenses_to_tenant_schema.php');
        foreach (DB::table('tenants')->pluck('schema_name') as $schemaName) {
            $migration->addToSchema($schemaName);
            foreach (PermissionCatalog::all() as $permission) {
                if (! str_starts_with($permission['key'], 'expenses.') && ! in_array($permission['key'], ['reports.expenses.view', 'reports.operating.view'], true)) {
                    continue;
                }
                DB::table($schemaName.'.permissions')->updateOrInsert(['key' => $permission['key']], $permission);
                foreach (DB::table($schemaName.'.roles')->where('is_system', true)->get() as $role) {
                    $scope = $role->slug === 'owner' ? 'all' : (PermissionCatalog::matrix()[$permission['key']][$role->slug] ?? null);
                    if ($scope) {
                        DB::table($schemaName.'.role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $role->id, 'permission_key' => $permission['key'], 'scope' => $scope]);
                    }
                }
            }
            DB::table($schemaName.'.users')->increment('permission_version');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $migration = require database_path('tenant/2026_10_11_222422_add_expenses_to_tenant_schema.php');
        $keys = ['expenses.view', 'expenses.create', 'expenses.void', 'reports.expenses.view', 'reports.operating.view'];
        foreach (DB::table('tenants')->pluck('schema_name') as $schemaName) {
            $migration->removeFromSchema($schemaName);
            DB::table($schemaName.'.role_permissions')->whereIn('permission_key', $keys)->delete();
            DB::table($schemaName.'.permissions')->whereIn('key', $keys)->delete();
            DB::table($schemaName.'.users')->increment('permission_version');
        }
    }
};
