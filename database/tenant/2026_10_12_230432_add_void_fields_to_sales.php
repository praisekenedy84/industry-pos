<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->addToSchema(DB::selectOne('SELECT current_schema() AS name')->name);
    }

    public function down(): void
    {
        throw new RuntimeException('Voided sales must stay in the audit history. Use a forward migration.');
    }

    public function addToSchema(string $schemaName): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]+$/', $schemaName)) {
            throw new RuntimeException('Invalid tenant schema name.');
        }

        $schema = DB::connection()->getSchemaBuilder();
        if (! $schema->hasTable($schemaName.'.sales') || $schema->hasColumn($schemaName.'.sales', 'voided_at')) {
            return;
        }
        $schema->table($schemaName.'.sales', function (Blueprint $table): void {
            $table->text('void_reason')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
        });
    }
};
