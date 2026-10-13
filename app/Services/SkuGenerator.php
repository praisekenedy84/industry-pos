<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class SkuGenerator
{
    /**
     * Allocate the next tenant SKU. Archived items keep their codes, so a number is never reused.
     */
    public function next(): string
    {
        return DB::connection('tenant')->transaction(function (): string {
            DB::connection('tenant')->table('settings')->insertOrIgnore([
                'key' => 'catalog.sku_next',
                'value' => json_encode(['next' => 1]),
            ]);

            $row = Setting::query()->where('key', 'catalog.sku_next')->lockForUpdate()->first();
            $stored = $row->value;
            $number = max(1, is_array($stored) ? (int) ($stored['next'] ?? 1) : (int) $stored);

            do {
                $sku = sprintf('SKU-%05d', $number);
                $number++;
            } while (Item::withTrashed()->where('sku', $sku)->exists());

            $row->value = ['next' => $number];
            $row->save();

            return $sku;
        });
    }
}
