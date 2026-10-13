<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;

class ApiTokenTenant extends \Illuminate\Database\Eloquent\Model
{
    use UsesCentralConnection;

    protected $primaryKey = 'token_hash';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
