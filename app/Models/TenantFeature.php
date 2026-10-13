<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

class TenantFeature extends Model
{
    use UsesCentralConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
