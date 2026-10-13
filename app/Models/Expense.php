<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory, UsesTenantConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'expense_date' => 'date:Y-m-d', 'voided_at' => 'datetime'];
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return ['rent' => 'Rent', 'utilities' => 'Utilities', 'payroll' => 'Payroll', 'transport' => 'Transport', 'maintenance' => 'Repairs & maintenance', 'marketing' => 'Marketing', 'office' => 'Office & administration', 'fees' => 'Bank & payment fees', 'other' => 'Other operating expenses'];
    }
}
