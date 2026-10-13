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
        throw new RuntimeException('Production traceability must be retained. Use a forward migration.');
    }

    public function addToSchema(string $schemaName): void
    {
        $database = DB::connection();
        $schema = $database->getSchemaBuilder();
        if (! $schema->hasTable($schemaName.'.production_orders') || $schema->hasColumn($schemaName.'.production_orders', 'actual_qty')) {
            return;
        }
        $schema->table($schemaName.'.production_orders', function (Blueprint $table): void {
            $table->decimal('actual_qty', 18, 4)->nullable();
            $table->string('lot_code')->nullable();
            $table->date('expiry_date')->nullable();
            $table->json('lot_attributes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('stock_posted_at')->nullable();
            $table->uuid('destination_branch_id')->nullable();
            $table->text('handover_note')->nullable();
        });
        $schema->table($schemaName.'.recipe_versions', function (Blueprint $table): void {
            $table->uuid('item_id')->nullable();
        });
        foreach ($database->table($schemaName.'.recipes')->get() as $recipe) {
            $database->table($schemaName.'.recipe_versions')->where('recipe_id', $recipe->id)->update(['item_id' => $recipe->item_id]);
        }
        $schema->table($schemaName.'.stock_lots', function (Blueprint $table): void {
            $table->json('attributes')->nullable();
        });
        $schema->table($schemaName.'.run_outputs', function (Blueprint $table): void {
            $table->string('run_number')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('stock_movement_id')->nullable();
        });
        $schema->create($schemaName.'.production_receipts', function (Blueprint $table) use ($schemaName): void {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->foreignUuid('branch_id')->constrained($schemaName.'.branches')->restrictOnDelete();
            $table->uuid('request_id');
            $table->foreignUuid('item_id')->constrained($schemaName.'.items')->restrictOnDelete();
            $table->foreignUuid('recipe_version_id')->nullable()->constrained($schemaName.'.recipe_versions')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->string('lot_code')->nullable();
            $table->date('expiry_date')->nullable();
            $table->json('lot_attributes')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('stock_posted_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'request_id']);
        });
        foreach ($database->table($schemaName.'.production_orders')->get() as $order) {
            $run = $database->table($schemaName.'.production_runs')->where('production_order_id', $order->id)->first();
            if (! $run) {
                continue;
            }
            $movement = $database->table($schemaName.'.stock_movements')->where('reference_type', 'production_run')
                ->where('reference_id', $run->id)->where('type', 'production_output')->first();
            $database->table($schemaName.'.production_orders')->where('id', $order->id)->update([
                'actual_qty' => $run->good_qty, 'started_at' => $run->started_at, 'completed_at' => $run->ended_at,
                'stock_posted_at' => $movement?->created_at,
            ]);
            $database->table($schemaName.'.run_outputs')->where('production_run_id', $run->id)->update([
                'run_number' => $order->number, 'branch_id' => $order->branch_id, 'stock_movement_id' => $movement?->id,
            ]);
        }
        $schema->table($schemaName.'.run_outputs', function (Blueprint $table): void {
            $table->unique(['branch_id', 'run_number']);
            $table->unique('production_run_id');
        });
        $schema->table($schemaName.'.production_orders', function (Blueprint $table) use ($schemaName): void {
            $table->foreign('recipe_id')->references('id')->on($schemaName.'.recipes')->restrictOnDelete();
            $table->foreign('recipe_version_id')->references('id')->on($schemaName.'.recipe_versions')->restrictOnDelete();
        });
        $schema->table($schemaName.'.recipe_versions', function (Blueprint $table) use ($schemaName): void {
            $table->foreign('recipe_id')->references('id')->on($schemaName.'.recipes')->restrictOnDelete();
        });
    }
};
