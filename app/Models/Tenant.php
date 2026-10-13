<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use UsesCentralConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'retention_until' => 'datetime',
            'trial_ends_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function workspace(): HasOne
    {
        return $this->hasOne(Workspace::class);
    }

    public function isSuspended(): bool
    {
        return in_array($this->status, ['suspended', 'pending_deletion'], true);
    }

    public function trialHasExpired(): bool
    {
        if ($this->status !== 'trial') {
            return false;
        }

        return $this->trial_ends_at === null || $this->trial_ends_at->isPast();
    }

    public function canUseSystem(): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        if ($this->status === 'active') {
            return true;
        }

        return $this->status === 'trial' && ! $this->trialHasExpired();
    }

    /**
     * Workspaces that may operate: approved, or still inside the signup trial.
     */
    #[Scope]
    protected function usable(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', 'active')
                ->orWhere(function (Builder $query): void {
                    $query->where('status', 'trial')->where('trial_ends_at', '>', now());
                });
        });
    }
}
