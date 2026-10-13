<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->addToSchema(DB::selectOne('SELECT current_schema() AS name')->name);
    }

    public function addToSchema(string $schemaName): void
    {
        $database = DB::connection();
        $schema = $database->getSchemaBuilder();
        if (! $schema->hasTable($schemaName.'.production_orders')) {
            return;
        }
        if (! $schema->hasTable($schemaName.'.production_lines')) {
            $schema->create($schemaName.'.production_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('branch_id')->index();
                $table->uuid('recipe_id')->index();
                $table->string('name');
                $table->string('code', 40);
                $table->string('status')->default('active');
                $table->decimal('daily_capacity', 18, 4)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['branch_id', 'code']);
            });
        }
        if (! $schema->hasColumn($schemaName.'.production_orders', 'production_line_id')) {
            $schema->table($schemaName.'.production_orders', function (Blueprint $table) use ($schemaName): void {
                $table->foreignUuid('production_line_id')->nullable()->constrained($schemaName.'.production_lines')->restrictOnDelete();
            });
        }
        $recipes = $database->table($schemaName.'.recipes')->whereNull('deleted_at')->get();
        foreach ($database->table($schemaName.'.branches')->get() as $branch) {
            foreach ($recipes as $recipe) {
                $line = $database->table($schemaName.'.production_lines')->where('branch_id', $branch->id)->where('recipe_id', $recipe->id)->first();
                $lineId = $line?->id ?? (string) Str::uuid();
                if (! $line) {
                    $database->table($schemaName.'.production_lines')->insert([
                        'id' => $lineId, 'branch_id' => $branch->id, 'recipe_id' => $recipe->id,
                        'name' => $recipe->name.' line', 'code' => 'LINE-'.substr($recipe->id, 0, 8),
                        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $database->table($schemaName.'.production_orders')
                    ->where('branch_id', $branch->id)->where('recipe_id', $recipe->id)->whereNull('production_line_id')
                    ->update(['production_line_id' => $lineId]);
            }
        }
    }

    public function removeFromSchema(string $schemaName): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if ($schema->hasColumn($schemaName.'.production_orders', 'production_line_id')) {
            $schema->table($schemaName.'.production_orders', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('production_line_id');
            });
        }
        $schema->dropIfExists($schemaName.'.production_lines');
    }

    public function down(): void
    {
        $this->removeFromSchema(DB::selectOne('SELECT current_schema() AS name')->name);
    }
};
