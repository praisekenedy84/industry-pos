<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use UsesTenantConnection, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
        ];
    }
}
