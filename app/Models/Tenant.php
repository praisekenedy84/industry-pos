<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends \Illuminate\Database\Eloquent\Model
{
    use UsesCentralConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'retention_until' => 'datetime',
        ];
    }

    public function workspace(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Workspace::class);
    }

    public function isSuspended(): bool
    {
        return in_array($this->status, ['suspended', 'pending_deletion'], true);
    }
}
