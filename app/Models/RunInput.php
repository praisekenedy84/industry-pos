<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class RunInput extends Model
{
    use UsesTenantConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'planned_qty' => 'decimal:4',
            'actual_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }
}
