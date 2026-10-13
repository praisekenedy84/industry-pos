<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    use UsesTenantConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'value' => 'decimal:4',
            'avg_cost' => 'decimal:4',
        ];
    }
}
