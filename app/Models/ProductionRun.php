<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class ProductionRun extends Model
{
    use UsesTenantConnection;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (ProductionRun $run): void {
            if ($run->getOriginal('inventory_value') !== null && $run->isDirty(['good_qty', 'waste_qty', 'material_cost', 'labor_cost', 'overhead_cost', 'batch_cost', 'unit_cost', 'inventory_value', 'waste_cost', 'loss_value', 'waste_treatment'])) {
                throw ValidationException::withMessages(['good_qty' => 'Completed quantities and costs are immutable. Reverse the batch to correct it.']);
            }
        });
    }

    public function waste(): HasOne
    {
        return $this->hasOne(RunWaste::class);
    }

    protected function casts(): array
    {
        return [
            'labor_hours' => 'decimal:4',
            'labor_cost' => 'decimal:4',
            'overhead_cost' => 'decimal:4',
            'material_cost' => 'decimal:4',
            'batch_cost' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'good_qty' => 'decimal:4',
            'waste_qty' => 'decimal:4',
            'inventory_value' => 'decimal:4',
            'waste_cost' => 'decimal:4',
            'loss_value' => 'decimal:4',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
