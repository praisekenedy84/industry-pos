<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    use UsesTenantConnection;

    protected $table = 'returns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'restock' => 'boolean',
        ];
    }
}
