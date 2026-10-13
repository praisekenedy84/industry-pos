<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->addToSchema(DB::selectOne('SELECT current_schema() AS name')->name);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->removeFromSchema(DB::selectOne('SELECT current_schema() AS name')->name);
    }

    public function addToSchema(string $schemaName): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if ($schema->hasTable($schemaName.'.expenses')) {
            return;
        }
        $schema->create($schemaName.'.expenses', function (Blueprint $table) use ($schemaName): void {
            $table->uuid('id')->primary();
            $table->string('number')->unique();
            $table->uuid('request_id');
            $table->foreignUuid('branch_id')->constrained($schemaName.'.branches')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained($schemaName.'.users')->restrictOnDelete();
            $table->date('expense_date');
            $table->string('category', 40);
            $table->string('description', 500);
            $table->string('payee', 200)->nullable();
            $table->decimal('amount', 18, 4);
            $table->string('payment_method');
            $table->string('reference', 200)->nullable();
            $table->string('status')->default('recorded');
            $table->foreignUuid('voided_by')->nullable()->constrained($schemaName.'.users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'request_id']);
            $table->index(['branch_id', 'expense_date', 'status']);
        });
        DB::statement('ALTER TABLE "'.$schemaName.'"."expenses" ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0)');
    }

    public function removeFromSchema(string $schemaName): void
    {
        DB::connection()->getSchemaBuilder()->dropIfExists($schemaName.'.expenses');
    }
};
