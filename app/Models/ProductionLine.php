<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionLine extends Model
{
    use HasFactory, UsesTenantConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['daily_capacity' => 'decimal:4'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }
}
