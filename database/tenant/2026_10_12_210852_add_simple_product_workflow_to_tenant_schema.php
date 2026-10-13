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
        throw new RuntimeException('Receipt and costing history must be retained. Use a forward migration.');
    }

    public function addToSchema(string $schemaName): void
    {
        $database = DB::connection();
        $schema = $database->getSchemaBuilder();
        if (! $schema->hasTable($schemaName.'.items') || $schema->hasColumn($schemaName.'.goods_receipts', 'request_id')) {
            return;
        }
        $schema->table($schemaName.'.goods_receipts', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable();
            $table->unique(['branch_id', 'request_id']);
            $table->string('reference')->nullable();
            $table->string('request_hash', 64)->nullable();
        });
        $schema->table($schemaName.'.production_orders', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable();
            $table->unique(['branch_id', 'request_id']);
        });
        $schema->table($schemaName.'.production_runs', function (Blueprint $table): void {
            $table->string('completion_hash', 64)->nullable();
        });
        $schema->table($schemaName.'.sale_lines', function (Blueprint $table): void {
            $table->decimal('total_cost', 18, 4)->nullable();
            $table->string('costing_status')->default('costed');
        });
        $schema->table($schemaName.'.stock_lots', function (Blueprint $table): void {
            $table->bigInteger('receipt_sequence')->generatedAs()->always();
        });
        $database->table($schemaName.'.sale_lines')->where('fulfillment', 'production')->where('unit_cost', 0)->update(['costing_status' => 'pending']);
    }
};
