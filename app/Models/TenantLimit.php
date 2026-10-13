<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

class TenantLimit extends Model
{
    use UsesCentralConnection;

    protected $guarded = ['id'];
}
