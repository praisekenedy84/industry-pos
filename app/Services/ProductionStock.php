<?php

namespace App\Services;

use App\Models\RecipeLine;
use App\Models\RecipeVersion;
use App\Models\StockMovement;
use App\Support\Bc;
use Illuminate\Support\Facades\Auth;

class ProductionStock
{
    public function __construct(private StockLedger $ledger) {}

    /** @return array{cost: string, inputs: array<int, array{item_id: string, qty: string, unit_cost: string}>} */
    public function consume(RecipeVersion $version, string $branchId, string $quantity, string $referenceType, string $referenceId): array
    {
        $cost = '0.0000';
        $inputs = [];
        $factor = Bc::div($quantity, $version->yield_qty);
        $groups = RecipeLine::query()->where('recipe_version_id', $version->id)->orderBy('item_id')->get()->groupBy('item_id');
        foreach ($groups as $itemId => $lines) {
            $billQty = $lines->reduce(fn (string $carry, RecipeLine $line) => Bc::add($carry, $line->qty), '0.0000');
            $qty = Bc::mul($billQty, $factor);
            $movement = StockMovement::query()->where('reference_type', $referenceType)->where('reference_id', $referenceId)
                ->where('type', 'production_consume')->where('item_id', $itemId)->first();
            if (! $movement) {
                $movement = $this->ledger->post([
                    'item_id' => $itemId, 'branch_id' => $branchId, 'type' => 'production_consume',
                    'quantity' => $qty, 'signed_qty' => Bc::mul($qty, '-1'), 'allow_negative' => true,
                    'reference_type' => $referenceType, 'reference_id' => $referenceId,
                    'user_id' => Auth::id(), 'reason' => 'Bill of materials '.$version->recipe_id,
                ]);
            }
            $cost = Bc::add($cost, $movement->total_cost);
            $inputs[] = ['item_id' => $itemId, 'qty' => Bc::num($movement->quantity), 'unit_cost' => Bc::num($movement->unit_cost)];
        }

        return ['cost' => $cost, 'inputs' => $inputs];
    }
}
