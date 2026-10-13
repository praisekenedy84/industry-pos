<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ProductionLine;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeCostLine;
use App\Models\RecipeLine;
use App\Models\RecipeVersion;
use App\Models\RunInput;
use App\Models\RunOutput;
use App\Models\RunStageLog;
use App\Models\RunWaste;
use App\Models\StockMovement;
use App\Support\Bc;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionService
{
    public function __construct(
        private StockLedger $ledger,
        private DocumentNumbers $numbers,
        private AuditLogger $audit,
        private ProductionStock $stock,
    ) {}

    public function plan(array $data): ProductionOrder
    {
        if (Bc::cmp($data['planned_qty'], 0) <= 0) {
            throw ValidationException::withMessages(['planned_qty' => 'Planned quantity must be greater than zero.']);
        }
        $recipe = Recipe::query()->findOrFail($data['recipe_id']);
        if (! $recipe->item?->track_stock || $recipe->item?->type === 'service' || ! $recipe->item?->base_unit_id) {
            throw ValidationException::withMessages(['recipe_id' => 'A production output must be a stocked good with a unit of measure.']);
        }
        $line = ProductionLine::query()->where('branch_id', $data['branch_id'])->where('recipe_id', $recipe->id)->where('status', 'active')
            ->when(! empty($data['production_line_id']), fn ($query) => $query->whereKey($data['production_line_id']))->orderBy('name')->first();
        if (! $line) {
            throw ValidationException::withMessages(['production_line_id' => 'Choose an active production line for this recipe and branch.']);
        }
        $version = RecipeVersion::query()
            ->where('recipe_id', $recipe->id)
            ->where('version', $recipe->current_version)
            ->firstOrFail();

        if (! $version->item_id) {
            $version->update(['item_id' => $recipe->item_id]);
        }
        $order = ProductionOrder::query()->create([
            'number' => $this->numbers->next($data['branch_id'], 'production'),
            'branch_id' => $data['branch_id'],
            'production_line_id' => $line->id,
            'recipe_id' => $recipe->id,
            'recipe_version_id' => $version->id,
            'planned_qty' => $data['planned_qty'],
            'due_date' => $data['due_date'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'planned',
            'sale_id' => $data['sale_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'lot_code' => $data['lot_code'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'lot_attributes' => $data['lot_attributes'] ?? null,
            'created_by' => Auth::id(),
        ]);
        $this->audit->tenant('production.status_changed', 'production_order', $order->id, ['status' => null], ['status' => 'planned']);
        $this->audit->tenant('production.planned', 'production_order', $order->id, null, $order->toArray());

        return $order;
    }

    public function start(ProductionOrder $order): void
    {
        DB::connection('tenant')->transaction(function () use ($order): void {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['draft', 'planned'], true)) {
                throw ValidationException::withMessages(['status' => 'Only planned batches can be started.']);
            }
            if ($order->productionLine?->status !== 'active') {
                throw ValidationException::withMessages(['production_line_id' => 'Activate this production line before starting a batch.']);
            }
            $previous = $order->status;
            $order->update(['status' => 'in_progress', 'started_at' => now()]);
            $this->audit->tenant('production.status_changed', 'production_order', $order->id, ['status' => $previous], ['status' => 'in_progress']);
            $this->audit->tenant('production.started', 'production_order', $order->id);
        });
    }

    public function requirements(ProductionOrder $order): array
    {
        if (! $order->recipe_version_id) {
            return [];
        }
        $version = RecipeVersion::query()->findOrFail($order->recipe_version_id);
        $factor = Bc::div($order->planned_qty, $version->yield_qty);
        $lines = [];
        foreach (RecipeLine::query()->where('recipe_version_id', $version->id)->get() as $line) {
            $need = Bc::mul($line->qty, $factor);
            $have = $this->ledger->onHand($line->item_id, $order->branch_id);
            $lines[] = [
                'item_id' => $line->item_id,
                'required' => $need,
                'available' => $have,
                'short' => Bc::cmp($have, $need) < 0,
                'unit_cost' => $this->ledger->averageCost($line->item_id, $order->branch_id),
            ];
        }

        return $lines;
    }

    public function hold(ProductionOrder $order, ?string $notes = null): void
    {
        if ($order->workflow_version === 2) {
            throw ValidationException::withMessages(['status' => 'Batches can be completed or cancelled.']);
        }
        $this->changeStatus($order, ['in_progress'], 'on_hold', $notes);
    }

    public function resume(ProductionOrder $order, ?string $notes = null): void
    {
        $this->changeStatus($order, ['on_hold'], 'in_progress', $notes);
    }

    public function cancel(ProductionOrder $order, ?string $notes = null): void
    {
        $this->changeStatus($order, ['draft', 'planned', 'in_progress', 'on_hold'], 'cancelled', $notes);
    }

    private function changeStatus(ProductionOrder $order, array $from, string $to, ?string $notes): void
    {
        DB::connection('tenant')->transaction(function () use ($order, $from, $to, $notes): void {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'This status change is not allowed.']);
            }
            $previous = $order->status;
            $order->update(['status' => $to]);
            $this->audit->tenant('production.status_changed', 'production_order', $order->id, ['status' => $previous], ['status' => $to, 'notes' => $notes]);
        });
    }

    public function release(ProductionOrder $order, array $input): ProductionRun
    {
        if ($order->workflow_version === 2) {
            throw ValidationException::withMessages(['status' => 'Use an inventory transfer for completed batch stock.']);
        }

        return DB::connection('tenant')->transaction(function () use ($order, $input): ProductionRun {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            $destination = $input['destination_branch_id'] ?? null;
            if ($order->status === 'released') {
                if ($destination !== $order->destination_branch_id) {
                    throw ValidationException::withMessages(['destination_branch_id' => 'This run has already been handed over.']);
                }

                return $this->complete($order, $input);
            }
            if (! in_array($order->status, ['in_progress', 'completed'], true)) {
                throw ValidationException::withMessages(['status' => 'Start or resume the run before releasing it.']);
            }
            if ($destination === $order->branch_id) {
                throw ValidationException::withMessages(['destination_branch_id' => 'Choose another location.']);
            }
            if (! $destination && empty($input['handover_note'])) {
                throw ValidationException::withMessages(['handover_note' => 'Record who received this handover.']);
            }
            $branches = Branch::query()->whereIn('id', array_filter([$order->branch_id, $destination]))->orderBy('id')->lockForUpdate()->get();
            if ($branches->contains(fn (Branch $branch) => $branch->status === 'suspended')) {
                throw ValidationException::withMessages(['destination_branch_id' => 'Suspended locations cannot transact.']);
            }
            if ($destination && ! $branches->contains('id', $destination)) {
                throw ValidationException::withMessages(['destination_branch_id' => 'Choose a valid receiving location.']);
            }
            $run = $this->complete($order, $input);
            $order->refresh();
            $output = RunOutput::query()->where('production_run_id', $run->id)->firstOrFail();
            if (Bc::cmp($this->ledger->onHand($output->item_id, $order->branch_id), $output->qty) < 0) {
                throw ValidationException::withMessages(['stock' => 'There is not enough finished stock left to hand over this run.']);
            }
            $this->ledger->post([
                'item_id' => $output->item_id, 'branch_id' => $order->branch_id, 'type' => 'production_release',
                'quantity' => $output->qty, 'signed_qty' => Bc::mul($output->qty, '-1'), 'unit_cost' => $output->unit_cost,
                'lot_id' => $output->lot_id, 'reference_type' => 'production_order', 'reference_id' => $order->id,
                'user_id' => Auth::id(), 'reason' => $input['handover_note'] ?? 'Production transfer '.$order->number,
            ]);
            if ($destination) {
                $this->ledger->post([
                    'item_id' => $output->item_id, 'branch_id' => $destination, 'type' => 'transfer_in',
                    'quantity' => $output->qty, 'signed_qty' => $output->qty, 'unit_cost' => $output->unit_cost,
                    'lot_code' => $order->lot_code ?? $order->number, 'expiry_date' => $order->expiry_date,
                    'reference_type' => 'production_order', 'reference_id' => $order->id,
                    'user_id' => Auth::id(), 'reason' => 'Production transfer '.$order->number,
                ]);
            }
            $order->update(['status' => 'released', 'released_at' => now(), 'destination_branch_id' => $destination, 'handover_note' => $input['handover_note'] ?? null]);
            $this->audit->tenant('production.status_changed', 'production_order', $order->id, ['status' => 'completed'], ['status' => 'released', 'destination_branch_id' => $destination, 'notes' => $input['handover_note'] ?? null]);

            return $run;
        });
    }

    public function backfillFinished(?string $branchId = null): int
    {
        $posted = 0;
        ProductionOrder::query()->whereIn('status', ['completed', 'released'])->whereNull('stock_posted_at')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->chunkById(100, function ($orders) use (&$posted): void {
                foreach ($orders as $order) {
                    $run = ProductionRun::query()->where('production_order_id', $order->id)->first();
                    $this->complete($order, $run ? ['labor_cost' => $run->labor_cost, 'overhead_cost' => $run->overhead_cost, 'waste_qty' => $run->waste_qty] : []);
                    $posted++;
                }
            });

        return $posted;
    }

    public function receive(array $data): ProductionReceipt
    {
        return DB::connection('tenant')->transaction(function () use ($data): ProductionReceipt {
            if (Bc::cmp($data['qty'], 0) <= 0) {
                throw ValidationException::withMessages(['qty' => 'Receipt quantity must be greater than zero.']);
            }
            Branch::query()->whereKey($data['branch_id'])->lockForUpdate()->firstOrFail();
            $receipt = ProductionReceipt::query()->where('branch_id', $data['branch_id'])->where('request_id', $data['request_id'])->first();
            if ($receipt) {
                if ($receipt->item_id !== $data['item_id'] || Bc::cmp($receipt->qty, $data['qty']) !== 0) {
                    throw ValidationException::withMessages(['request_id' => 'This receipt request has already been used for different goods.']);
                }

                return $receipt;
            }
            $item = Item::query()->findOrFail($data['item_id']);
            if (! $item->track_stock || $item->type === 'service' || ! $item->base_unit_id) {
                throw ValidationException::withMessages(['item_id' => 'Choose a stocked good with a unit of measure.']);
            }
            $recipe = Recipe::query()->where('item_id', $item->id)->orderBy('created_at')->first();
            $version = $recipe ? RecipeVersion::query()->where('recipe_id', $recipe->id)->where('version', $recipe->current_version)->firstOrFail() : null;
            $receipt = ProductionReceipt::query()->create([
                'number' => $this->numbers->next($data['branch_id'], 'direct_receipt'), 'branch_id' => $data['branch_id'],
                'request_id' => $data['request_id'], 'item_id' => $item->id, 'recipe_version_id' => $version?->id,
                'qty' => $data['qty'], 'unit_cost' => $data['unit_cost'] ?? 0, 'lot_code' => $data['lot_code'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null, 'lot_attributes' => $data['lot_attributes'] ?? null,
                'notes' => $data['notes'] ?? null, 'created_by' => Auth::id(),
            ]);
            $unitCost = Bc::num($data['unit_cost'] ?? 0);
            if ($version) {
                $consumption = $this->stock->consume($version, $receipt->branch_id, $receipt->qty, 'production_receipt', $receipt->id);
                $extra = Bc::num(RecipeCostLine::query()->where('recipe_version_id', $version->id)->sum('amount'));
                $unitCost = Bc::div(Bc::add($consumption['cost'], $extra), $receipt->qty);
            }
            if (isset($data['max_value']) && Bc::cmp(Bc::mul($receipt->qty, $unitCost), $data['max_value']) > 0) {
                throw ValidationException::withMessages(['qty' => 'Receipt exceeds your stock adjustment limit.']);
            }
            $this->ledger->post([
                'item_id' => $item->id, 'branch_id' => $receipt->branch_id, 'type' => 'direct_receipt',
                'quantity' => $receipt->qty, 'signed_qty' => $receipt->qty, 'unit_cost' => $unitCost,
                'lot_code' => $receipt->lot_code ?? $receipt->number, 'expiry_date' => $receipt->expiry_date,
                'lot_attributes' => $receipt->lot_attributes,
                'reference_type' => 'production_receipt', 'reference_id' => $receipt->id,
                'user_id' => Auth::id(), 'reason' => $receipt->notes ?? 'Direct receipt '.$receipt->number,
            ]);
            $receipt->update(['unit_cost' => $unitCost, 'stock_posted_at' => now(), 'lot_code' => $receipt->lot_code ?? $receipt->number]);
            $this->audit->tenant('production.receipt.posted', 'production_receipt', $receipt->id, null, $receipt->toArray());

            return $receipt;
        });
    }

    public function estimate(ProductionOrder $order): array
    {
        if (! $order->recipe_version_id || ! $order->planned_qty) {
            return ['material' => null, 'extra' => null, 'batch' => null, 'unit' => null];
        }
        $material = '0.0000';
        foreach ($this->requirements($order) as $line) {
            $material = Bc::add($material, Bc::mul($line['required'], $line['unit_cost']));
        }
        $extra = RecipeCostLine::query()->where('recipe_version_id', $order->recipe_version_id)->sum('amount');
        $batch = Bc::add($material, $extra);
        $unit = Bc::div($batch, $order->planned_qty);

        return ['material' => $material, 'extra' => Bc::num($extra), 'batch' => $batch, 'unit' => $unit];
    }

    public function complete(ProductionOrder $order, array $input): ProductionRun
    {
        if ($order->workflow_version === 2) {
            return app(BatchProduction::class)->complete($order, $input);
        }

        return DB::connection('tenant')->transaction(function () use ($order, $input) {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (in_array($order->status, ['on_hold', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => 'Resume this run before completing it. Cancelled runs cannot be completed.']);
            }
            $existing = ProductionRun::query()->where('production_order_id', $order->id)->first();
            $good = Bc::num($input['good_qty'] ?? $order->actual_qty ?? ($existing && Bc::cmp($existing->good_qty, '0') > 0 ? $existing->good_qty : $order->planned_qty));
            if (Bc::cmp($good, '0') <= 0) {
                throw ValidationException::withMessages(['good_qty' => 'Actual output must be greater than zero.']);
            }
            if ($order->stock_posted_at) {
                if (Bc::cmp($good, $order->actual_qty) !== 0) {
                    throw ValidationException::withMessages(['good_qty' => 'Posted output cannot be changed.']);
                }

                return $existing ?? throw new \RuntimeException('Posted production is missing its run.');
            }
            if (in_array($order->status, ['draft', 'planned'], true)) {
                $this->start($order);
                $order->refresh();
            }
            $version = RecipeVersion::query()->findOrFail($order->recipe_version_id);
            $recipe = Recipe::query()->withTrashed()->findOrFail($order->recipe_id);
            $itemId = $version->item_id ?? $recipe->item_id;
            $run = $existing ?? ProductionRun::query()->create([
                'production_order_id' => $order->id, 'status' => 'in_progress',
                'started_at' => $order->started_at ?? now(), 'created_by' => Auth::id(),
            ]);
            $posted = StockMovement::query()->where('reference_type', 'production_run')->where('reference_id', $run->id)->where('type', 'production_output')->first();
            if ($posted) {
                RunOutput::query()->updateOrCreate(['production_run_id' => $run->id], [
                    'item_id' => $posted->item_id, 'qty' => $posted->quantity, 'unit_cost' => $posted->unit_cost,
                    'lot_id' => $posted->lot_id, 'stock_movement_id' => $posted->id, 'run_number' => $order->number, 'branch_id' => $order->branch_id,
                ]);
                $order->update(['stock_posted_at' => $posted->created_at, 'actual_qty' => $posted->quantity]);

                return $run;
            }
            $consumption = $this->stock->consume($version, $order->branch_id, $good, 'production_run', $run->id);
            $material = $consumption['cost'];
            foreach ($consumption['inputs'] as $line) {
                RunInput::query()->updateOrCreate(['production_run_id' => $run->id, 'item_id' => $line['item_id']], [
                    'planned_qty' => Bc::mul($line['qty'], Bc::div($order->planned_qty, $good)),
                    'actual_qty' => $line['qty'], 'unit_cost' => $line['unit_cost'],
                ]);
            }

            $labor = array_key_exists('labor_cost', $input)
                ? Bc::num($input['labor_cost'])
                : Bc::num(RecipeCostLine::query()->where('recipe_version_id', $version->id)->where('type', 'labor')->sum('amount'));
            $overhead = array_key_exists('overhead_cost', $input)
                ? Bc::num($input['overhead_cost'])
                : Bc::num(RecipeCostLine::query()->where('recipe_version_id', $version->id)->where('type', 'overhead')->sum('amount'));
            $batch = Bc::add(Bc::add($material, $labor), $overhead);
            $waste = Bc::num($input['waste_qty'] ?? 0);
            if (Bc::cmp($good, '0') <= 0) {
                throw ValidationException::withMessages(['good_qty' => 'Good output must be greater than zero so unit cost can be calculated.']);
            }
            $unit = Bc::div($batch, $good);

            $output = $this->ledger->post([
                'item_id' => $itemId,
                'branch_id' => $order->branch_id,
                'type' => 'production_output',
                'quantity' => $good,
                'signed_qty' => $good,
                'unit_cost' => $unit,
                'lot_code' => $input['lot_code'] ?? $order->lot_code ?? $order->number,
                'expiry_date' => $input['expiry_date'] ?? $order->expiry_date,
                'lot_attributes' => $order->lot_attributes,
                'reference_type' => 'production_run',
                'reference_id' => $run->id,
                'user_id' => Auth::id(),
                'reason' => 'Output '.$order->number,
            ]);

            RunOutput::query()->updateOrCreate(['production_run_id' => $run->id], [
                'item_id' => $itemId,
                'qty' => $good,
                'unit_cost' => $unit,
                'lot_id' => $output->lot_id,
                'run_number' => $order->number,
                'branch_id' => $order->branch_id,
                'stock_movement_id' => $output->id,
            ]);

            if (Bc::cmp($waste, '0') > 0) {
                $this->ledger->post([
                    'item_id' => $itemId,
                    'branch_id' => $order->branch_id,
                    'type' => 'wastage',
                    'quantity' => $waste,
                    'signed_qty' => '0',
                    'unit_cost' => '0',
                    'reference_type' => 'production_run',
                    'reference_id' => $run->id,
                    'user_id' => Auth::id(),
                    'reason' => $input['waste_reason'] ?? 'Yield loss',
                ]);
                RunWaste::query()->create([
                    'production_run_id' => $run->id,
                    'item_id' => $itemId,
                    'qty' => $waste,
                    'reason' => $input['waste_reason'] ?? 'Yield loss',
                    'stage_name' => $input['stage_name'] ?? null,
                ]);
            }

            foreach ($input['stages'] ?? [] as $stage) {
                RunStageLog::query()->create([
                    'production_run_id' => $run->id,
                    'recipe_stage_id' => $stage['recipe_stage_id'] ?? null,
                    'name' => $stage['name'],
                    'status' => $stage['status'] ?? 'done',
                    'assignee_id' => $stage['assignee_id'] ?? Auth::id(),
                    'started_at' => $stage['started_at'] ?? now(),
                    'ended_at' => $stage['ended_at'] ?? now(),
                    'quality_passed' => $stage['quality_passed'] ?? true,
                    'notes' => $stage['notes'] ?? null,
                ]);
            }

            $run->update([
                'status' => 'completed',
                'ended_at' => now(),
                'labor_hours' => $input['labor_hours'] ?? 0,
                'labor_cost' => $labor,
                'overhead_cost' => $overhead,
                'material_cost' => $material,
                'batch_cost' => $batch,
                'unit_cost' => $unit,
                'good_qty' => $good,
                'waste_qty' => $waste,
            ]);
            if ($order->status !== 'completed' && $order->status !== 'released') {
                $this->audit->tenant('production.status_changed', 'production_order', $order->id, ['status' => $order->status], ['status' => 'completed']);
            }
            $order->update(['status' => $order->status === 'released' ? 'released' : 'completed', 'actual_qty' => $good, 'completed_at' => $order->completed_at ?? now(), 'stock_posted_at' => now(), 'lot_code' => $input['lot_code'] ?? $order->lot_code ?? $order->number, 'expiry_date' => $input['expiry_date'] ?? $order->expiry_date]);
            $this->audit->tenant('production.completed', 'production_run', $run->id, null, [
                'good_qty' => $good,
                'waste_qty' => $waste,
                'unit_cost' => $unit,
                'batch_cost' => $batch,
            ]);

            return $run->fresh();
        });
    }
}
