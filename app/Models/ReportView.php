<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class ReportView extends Model
{
    use UsesTenantConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }
}
