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
        throw new RuntimeException('Batch and accounting history must be retained. Use a forward migration.');
    }

    public function addToSchema(string $schemaName): void
    {
        $database = DB::connection();
        $schema = $database->getSchemaBuilder();
        if (! $schema->hasTable($schemaName.'.production_orders') || $schema->hasColumn($schemaName.'.production_orders', 'workflow_version')) {
            return;
        }
        $schema->table($schemaName.'.production_orders', function (Blueprint $table) use ($schemaName): void {
            $table->unsignedSmallInteger('workflow_version')->default(1);
            $table->foreignUuid('item_id')->nullable()->constrained($schemaName.'.items')->restrictOnDelete();
            $table->foreignUuid('output_unit_id')->nullable()->constrained($schemaName.'.units')->restrictOnDelete();
            $table->uuid('recipe_id')->nullable()->change();
            $table->uuid('recipe_version_id')->nullable()->change();
            $table->decimal('planned_qty', 18, 4)->nullable()->change();
            $table->boolean('track_materials')->nullable();
        });
        $schema->table($schemaName.'.items', function (Blueprint $table): void {
            $table->decimal('production_unit_cost', 18, 4)->nullable();
        });
        $schema->table($schemaName.'.production_runs', function (Blueprint $table): void {
            $table->decimal('inventory_value', 18, 4)->nullable();
            $table->decimal('waste_cost', 18, 4)->nullable();
            $table->decimal('loss_value', 18, 4)->nullable();
            $table->string('waste_treatment')->nullable();
            $table->text('notes')->nullable();
        });
        $schema->table($schemaName.'.run_waste', function (Blueprint $table) use ($schemaName): void {
            $table->foreignUuid('unit_id')->nullable()->constrained($schemaName.'.units')->restrictOnDelete();
            $table->decimal('output_equivalent_qty', 18, 4)->nullable();
            $table->decimal('cost_value', 18, 4)->nullable();
            $table->foreignUuid('branch_id')->nullable()->constrained($schemaName.'.branches')->restrictOnDelete();
            $table->uuid('recorded_by')->nullable();
            $table->timestamp('recorded_at')->nullable();
        });
        $schema->create($schemaName.'.production_journals', function (Blueprint $table) use ($schemaName): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_run_id')->constrained($schemaName.'.production_runs')->restrictOnDelete();
            $table->string('kind')->default('completion');
            $table->unique(['production_run_id', 'kind']);
            $table->uuid('reverses_id')->nullable()->unique();
            $table->text('reason')->nullable();
            $table->foreignUuid('branch_id')->constrained($schemaName.'.branches')->restrictOnDelete();
            $table->string('number')->unique();
            $table->string('waste_treatment');
            $table->decimal('batch_cost', 18, 4);
            $table->decimal('inventory_value', 18, 4);
            $table->decimal('loss_value', 18, 4);
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at');
        });
        $schema->create($schemaName.'.production_journal_lines', function (Blueprint $table) use ($schemaName): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_journal_id')->constrained($schemaName.'.production_journals')->restrictOnDelete();
            $table->string('account_code');
            $table->string('purpose');
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
        });
    }
};
