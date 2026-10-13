<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Workspace extends \Illuminate\Database\Eloquent\Model
{
    use UsesCentralConnection;

    protected $guarded = ['id'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'active' && ! $this->tenant?->isSuspended();
    }
}
