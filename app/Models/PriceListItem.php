<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    use UsesTenantConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
