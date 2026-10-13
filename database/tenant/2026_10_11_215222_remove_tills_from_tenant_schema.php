<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->removeFromSchema(DB::selectOne('SELECT current_schema() AS name')->name);
    }

    public function removeFromSchema(string $schemaName): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]+$/', $schemaName)) {
            throw new RuntimeException('Invalid tenant schema name.');
        }

        $database = DB::connection();
        $schema = $database->getSchemaBuilder();
        if (! $schema->hasTable($schemaName.'.sales')) {
            return;
        }
        if ($schema->hasColumn($schemaName.'.sales', 'till_session_id')) {
            $schema->table($schemaName.'.sales', function (Blueprint $table): void {
                $table->dropColumn('till_session_id');
            });
        }
        $schema->dropIfExists($schemaName.'.till_movements');
        $schema->dropIfExists($schemaName.'.till_sessions');

        $permissions = ['pos.till.open', 'pos.till.close', 'pos.till.review'];
        foreach (['role_permissions', 'user_permission_overrides'] as $table) {
            $database->table($schemaName.'.'.$table)->whereIn('permission_key', $permissions)->delete();
        }
        $database->table($schemaName.'.permissions')->whereIn('key', $permissions)->delete();
        foreach (['report_views', 'report_subscriptions'] as $table) {
            $database->table($schemaName.'.'.$table)->whereIn('report_key', ['cash.tills', 'cash.movements'])->delete();
        }
        $database->table($schemaName.'.users')->increment('permission_version');
    }

    public function down(): void
    {
        throw new RuntimeException('Till removal cannot be reversed because its historical session data has been removed.');
    }
};
