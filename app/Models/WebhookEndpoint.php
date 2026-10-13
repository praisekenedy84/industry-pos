<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    use UsesTenantConnection;

    protected $table = 'webhooks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'active' => 'boolean',
        ];
    }
}
