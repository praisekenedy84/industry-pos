<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionReceipt extends Model
{
    use HasFactory, UsesTenantConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'expiry_date' => 'date', 'lot_attributes' => 'array', 'stock_posted_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
