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

    public function addToSchema(string $schemaName): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (! $schema->hasTable($schemaName.'.recipe_lines') || $schema->hasColumn($schemaName.'.recipe_lines', 'unit_cost')) {
            return;
        }
        $schema->table($schemaName.'.recipe_lines', function (Blueprint $table): void {
            $table->decimal('unit_cost', 18, 4)->default(0);
        });
    }

    public function removeFromSchema(string $schemaName): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if ($schema->hasColumn($schemaName.'.recipe_lines', 'unit_cost')) {
            $schema->table($schemaName.'.recipe_lines', function (Blueprint $table): void {
                $table->dropColumn('unit_cost');
            });
        }
    }

    public function down(): void
    {
        $this->removeFromSchema(DB::selectOne('SELECT current_schema() AS name')->name);
    }
};
