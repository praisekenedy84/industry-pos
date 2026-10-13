<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class ProductionOrder extends Model
{
    use UsesTenantConnection;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (ProductionOrder $order): void {
            if ($order->getOriginal('workflow_version') === 2 && $order->getOriginal('stock_posted_at') && $order->isDirty(['actual_qty', 'planned_qty', 'item_id', 'recipe_id', 'recipe_version_id', 'output_unit_id', 'branch_id', 'track_materials'])) {
                throw ValidationException::withMessages(['good_qty' => 'Completed batch quantities are immutable. Reverse the batch to correct it.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'planned_qty' => 'decimal:4',
            'workflow_version' => 'integer',
            'track_materials' => 'boolean',
            'actual_qty' => 'decimal:4',
            'due_date' => 'date',
            'expiry_date' => 'date',
            'lot_attributes' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'released_at' => 'datetime',
            'stock_posted_at' => 'datetime',
        ];
    }

    public function productionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withTrashed();
    }

    public function run(): HasOne
    {
        return $this->hasOne(ProductionRun::class);
    }
}
