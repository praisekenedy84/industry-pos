<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    use UsesTenantConnection;

    public $timestamps = false;

    protected $guarded = ['id'];
}
