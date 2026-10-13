<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class GoodsReceipt extends Model
{
    use UsesTenantConnection;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (GoodsReceipt $receipt): void {
            if ($receipt->getOriginal('request_id') && $receipt->isDirty()) {
                throw ValidationException::withMessages(['stock' => 'Posted receipts cannot be edited. Use an auditable stock adjustment.']);
            }
        });
        static::deleting(function (GoodsReceipt $receipt): void {
            if ($receipt->request_id) {
                throw ValidationException::withMessages(['stock' => 'Posted receipts cannot be deleted. Use an auditable stock adjustment.']);
            }
        });
    }
}
