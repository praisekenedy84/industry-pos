<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use UsesTenantConnection, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'balance' => 'decimal:4',
        ];
    }
}
