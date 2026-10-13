<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use UsesTenantConnection;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'key';

    public $timestamps = false;

    protected $guarded = ['id'];
}
