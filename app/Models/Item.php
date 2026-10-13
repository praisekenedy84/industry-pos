<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes, UsesTenantConnection;

    protected $guarded = ['id'];

    public function billsOfMaterials(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    protected function casts(): array
    {
        return [
            'track_stock' => 'boolean',
            'reorder_level' => 'decimal:4',
            'default_price' => 'decimal:4',
            'production_unit_cost' => 'decimal:4',
        ];
    }
}
