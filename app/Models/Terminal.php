<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class Terminal extends Model
{
    use UsesTenantConnection;

    protected $guarded = ['id'];
}
