<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'signed_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Stock movements are append-only.');
        });
        static::deleting(function (): void {
            throw new \RuntimeException('Stock movements are append-only.');
        });
    }
}
