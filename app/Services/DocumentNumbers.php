<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\NumberSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumbers
{
    public function next(string $branchId, string $type): string
    {
        return DB::connection('tenant')->transaction(function () use ($branchId, $type) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);
            if ($type === 'production') {
                $date = now(app('makerly.timezone'))->format('Ymd');
                $seq = NumberSequence::query()->firstOrCreate(
                    ['branch_id' => $branchId, 'document_type' => 'production-'.$date],
                    ['prefix' => 'PR-'.$date.'-', 'next_number' => 1],
                );
                $number = $seq->prefix.str_pad((string) $seq->next_number, 4, '0', STR_PAD_LEFT);
                $seq->increment('next_number');

                return $number;
            }
            $prefix = $branch->code.'-'.strtoupper(substr($type, 0, 3)).'-';
            $seq = NumberSequence::query()->lockForUpdate()->firstOrCreate(
                ['branch_id' => $branchId, 'document_type' => $type],
                ['prefix' => $prefix, 'next_number' => 1],
            );
            $number = $seq->prefix.str_pad((string) $seq->next_number, 5, '0', STR_PAD_LEFT);
            $seq->next_number = $seq->next_number + 1;
            $seq->save();

            return $number;
        });
    }
}
