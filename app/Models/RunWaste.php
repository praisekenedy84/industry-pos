<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class RunWaste extends Model
{
    use UsesTenantConnection;

    public $timestamps = false;

    protected $table = 'run_waste';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (RunWaste $waste): void {
            if ($waste->getOriginal('cost_value') !== null && $waste->isDirty()) {
                throw ValidationException::withMessages(['waste_qty' => 'Posted waste is immutable. Reverse the batch to correct it.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'cost_value' => 'decimal:4',
            'output_equivalent_qty' => 'decimal:4',
            'recorded_at' => 'datetime',
        ];
    }
}
