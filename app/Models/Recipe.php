<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Recipe extends Model
{
    use SoftDeletes, UsesTenantConnection;

    protected $guarded = ['id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (Recipe $recipe): void {
            $usedByRun = ProductionOrder::query()->where('recipe_id', $recipe->id)->exists();
            $usedByReceipt = ProductionReceipt::query()->whereIn('recipe_version_id', RecipeVersion::query()->where('recipe_id', $recipe->id)->select('id'))->exists();
            if ($usedByRun || $usedByReceipt) {
                throw ValidationException::withMessages(['recipe' => 'A bill of materials used by production cannot be deleted.']);
            }
        });
    }
}
