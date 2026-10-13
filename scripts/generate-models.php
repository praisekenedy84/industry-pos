<?php

$root = dirname(__DIR__);
$models = [
    'Domain' => ['central' => true],
    'Feature' => ['central' => true, 'casts' => ['default_value' => 'array', 'globally_enabled' => 'boolean']],
    'TenantFeature' => ['central' => true, 'casts' => ['value' => 'array', 'expires_at' => 'datetime']],
    'TenantLimit' => ['central' => true],
    'ImpersonationLog' => ['central' => true, 'casts' => ['started_at' => 'datetime', 'ended_at' => 'datetime', 'expires_at' => 'datetime']],
    'IndustryTemplate' => ['central' => true, 'casts' => ['payload' => 'array']],
    'CentralAuditLog' => ['central' => true, 'casts' => ['before' => 'array', 'after' => 'array'], 'timestamps' => false],
    'LoginLink' => ['central' => true, 'casts' => ['expires_at' => 'datetime', 'used_at' => 'datetime']],
    'Branch' => [],
    'TaxRate' => ['casts' => ['rate' => 'decimal:4', 'is_default' => 'boolean']],
    'PaymentMethod' => ['casts' => ['enabled' => 'boolean', 'requires_reference' => 'boolean']],
    'NumberSequence' => ['timestamps' => false],
    'Role' => ['casts' => ['is_system' => 'boolean', 'is_locked' => 'boolean', 'archived_at' => 'datetime']],
    'Permission' => ['primary' => 'key', 'keyType' => 'string', 'incrementing' => false, 'timestamps' => false, 'uuid' => false],
    'RolePermission' => ['casts' => ['constraints' => 'array'], 'timestamps' => false],
    'RoleVersion' => ['casts' => ['snapshot' => 'array'], 'timestamps' => false],
    'UserBranchRole' => ['timestamps' => false],
    'UserPermissionOverride' => ['casts' => ['constraints' => 'array', 'expires_at' => 'datetime']],
    'ApprovalRequest' => ['casts' => ['payload' => 'array']],
    'AuditLog' => ['casts' => ['before' => 'array', 'after' => 'array'], 'timestamps' => false],
    'ActiveSession' => ['timestamps' => false],
    'RememberDevice' => ['timestamps' => false],
    'Category' => ['soft' => true],
    'Unit' => [],
    'UnitConversion' => ['casts' => ['factor' => 'decimal:6'], 'timestamps' => false],
    'Item' => ['soft' => true, 'casts' => ['track_stock' => 'boolean', 'reorder_level' => 'decimal:4', 'default_price' => 'decimal:4']],
    'ItemVariant' => ['casts' => ['price_delta' => 'decimal:4']],
    'ItemBarcode' => ['timestamps' => false],
    'PriceList' => ['casts' => ['is_default' => 'boolean']],
    'PriceListItem' => ['casts' => ['price' => 'decimal:4', 'starts_at' => 'datetime', 'ends_at' => 'datetime'], 'timestamps' => false],
    'CustomFieldDef' => ['casts' => ['options' => 'array'], 'timestamps' => false],
    'CustomFieldValue' => ['timestamps' => false],
    'StockLot' => ['casts' => ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'expiry_date' => 'date']],
    'StockBalance' => ['casts' => ['qty' => 'decimal:4', 'value' => 'decimal:4', 'avg_cost' => 'decimal:4'], 'timestamps' => false],
    'Transfer' => [],
    'TransferLine' => ['casts' => ['qty' => 'decimal:4', 'qty_received' => 'decimal:4'], 'timestamps' => false],
    'StockCount' => ['casts' => ['blind' => 'boolean']],
    'StockCountLine' => ['casts' => ['expected_qty' => 'decimal:4', 'counted_qty' => 'decimal:4'], 'timestamps' => false],
    'Recipe' => ['soft' => true],
    'RecipeVersion' => ['casts' => ['yield_qty' => 'decimal:4', 'wastage_percent' => 'decimal:4'], 'timestamps' => false],
    'RecipeLine' => ['casts' => ['qty' => 'decimal:4', 'wastage_percent' => 'decimal:4'], 'timestamps' => false],
    'RecipeCostLine' => ['casts' => ['amount' => 'decimal:4'], 'timestamps' => false],
    'RecipeStage' => ['casts' => ['requires_quality' => 'boolean'], 'timestamps' => false],
    'ProductionOrder' => ['casts' => ['planned_qty' => 'decimal:4', 'due_date' => 'date']],
    'ProductionRun' => ['casts' => ['labor_hours' => 'decimal:4', 'labor_cost' => 'decimal:4', 'overhead_cost' => 'decimal:4', 'material_cost' => 'decimal:4', 'batch_cost' => 'decimal:4', 'unit_cost' => 'decimal:4', 'good_qty' => 'decimal:4', 'waste_qty' => 'decimal:4', 'started_at' => 'datetime', 'ended_at' => 'datetime']],
    'RunInput' => ['casts' => ['planned_qty' => 'decimal:4', 'actual_qty' => 'decimal:4', 'unit_cost' => 'decimal:4'], 'timestamps' => false],
    'RunOutput' => ['casts' => ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4'], 'timestamps' => false],
    'RunWaste' => ['casts' => ['qty' => 'decimal:4'], 'timestamps' => false],
    'RunStageLog' => ['casts' => ['quality_passed' => 'boolean', 'started_at' => 'datetime', 'ended_at' => 'datetime'], 'timestamps' => false],
    'Supplier' => ['soft' => true, 'casts' => ['balance' => 'decimal:4']],
    'SupplierPrice' => ['casts' => ['price' => 'decimal:4'], 'timestamps' => false],
    'PurchaseOrder' => ['casts' => ['expected_at' => 'date']],
    'PurchaseOrderLine' => ['casts' => ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'qty_received' => 'decimal:4'], 'timestamps' => false],
    'GoodsReceipt' => [],
    'GoodsReceiptLine' => ['casts' => ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'expiry_date' => 'date'], 'timestamps' => false],
    'SupplierReturn' => ['casts' => ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4']],
    'Customer' => ['soft' => true, 'casts' => ['credit_limit' => 'decimal:4', 'balance' => 'decimal:4']],
    'CustomerPayment' => ['casts' => ['amount' => 'decimal:4'], 'timestamps' => false],
    'Terminal' => [],
    'TillSession' => ['casts' => ['opening_float' => 'decimal:4', 'expected_cash' => 'decimal:4', 'counted_cash' => 'decimal:4', 'variance' => 'decimal:4', 'opened_at' => 'datetime', 'closed_at' => 'datetime']],
    'TillMovement' => ['casts' => ['amount' => 'decimal:4'], 'timestamps' => false],
    'Sale' => ['casts' => ['subtotal' => 'decimal:4', 'discount_total' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4', 'prices_include_tax' => 'boolean']],
    'SaleLine' => ['casts' => ['qty' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount' => 'decimal:4', 'tax_rate' => 'decimal:4', 'net' => 'decimal:4', 'tax_amount' => 'decimal:4', 'gross' => 'decimal:4', 'unit_cost' => 'decimal:4'], 'timestamps' => false],
    'SalePayment' => ['casts' => ['amount' => 'decimal:4'], 'timestamps' => false],
    'SaleReturn' => ['table' => 'returns', 'casts' => ['restock' => 'boolean']],
    'ReturnLine' => ['casts' => ['qty' => 'decimal:4', 'restock' => 'boolean'], 'timestamps' => false],
    'Receipt' => ['casts' => [], 'timestamps' => false],
    'SystemUser' => [],
    'ApiToken' => ['casts' => ['scopes' => 'array', 'branch_ids' => 'array', 'ip_allowlist' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime']],
    'UserNotification' => ['table' => 'notifications', 'casts' => ['data' => 'array', 'read_at' => 'datetime'], 'timestamps' => false],
    'TranslationOverride' => ['timestamps' => false],
    'WebhookEndpoint' => ['table' => 'webhooks', 'casts' => ['events' => 'array', 'active' => 'boolean']],
    'WebhookDelivery' => ['casts' => ['payload' => 'array'], 'timestamps' => false],
    'PosDraft' => ['casts' => ['cart' => 'array']],
    'Target' => ['casts' => ['target_amount' => 'decimal:4']],
    'ReportSubscription' => ['casts' => ['filters' => 'array', 'last_sent_at' => 'datetime']],
    'ReportView' => ['casts' => ['filters' => 'array']],
];

$dir = $root.'/app/Models';
foreach ($models as $class => $opt) {
    $central = $opt['central'] ?? false;
    $trait = $central ? 'UsesCentralConnection' : 'UsesTenantConnection';
    $uses = ["use App\\Models\\Concerns\\{$trait};"];
    $traits = [$trait];
    if ($opt['soft'] ?? false) {
        $uses[] = 'use Illuminate\\Database\\Eloquent\\SoftDeletes;';
        $traits[] = 'SoftDeletes';
    }
    $uses[] = 'use Illuminate\\Database\\Eloquent\\Model;';
    $body = [];
    if (isset($opt['table'])) {
        $body[] = "    protected \$table = '{$opt['table']}';";
    }
    if (($opt['uuid'] ?? true) === false) {
        $body[] = '    public $incrementing = false;';
        $body[] = "    protected \$keyType = 'string';";
        $body[] = "    protected \$primaryKey = '".($opt['primary'] ?? 'key')."';";
    }
    if (($opt['timestamps'] ?? true) === false) {
        $body[] = '    public $timestamps = false;';
    }
    $body[] = "    protected \$guarded = ['id'];";
    if (! empty($opt['casts'])) {
        $lines = ["    protected function casts(): array", '    {', '        return ['];
        foreach ($opt['casts'] as $col => $cast) {
            $lines[] = "            '$col' => '$cast',";
        }
        $lines[] = '        ];';
        $lines[] = '    }';
        $body[] = implode("\n", $lines);
    }
    $traitList = implode(', ', $traits);
    $useBlock = implode("\n", $uses);
    $bodyBlock = implode("\n\n", $body);
    $php = <<<PHP
<?php

namespace App\Models;

{$useBlock}

class {$class} extends Model
{
    use {$traitList};

{$bodyBlock}
}

PHP;
    file_put_contents($dir.'/'.$class.'.php', $php);
}

echo "wrote ".count($models)." models\n";
