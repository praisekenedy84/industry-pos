<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class GoodsReceiptLine extends Model
{
    use UsesTenantConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        $guard = function (GoodsReceiptLine $line): void {
            if (GoodsReceipt::query()->whereKey($line->getOriginal('goods_receipt_id'))->whereNotNull('request_id')->exists()) {
                throw ValidationException::withMessages(['stock' => 'Posted receipt costs and quantities are immutable. Use an auditable stock adjustment.']);
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'expiry_date' => 'date',
        ];
    }
}
