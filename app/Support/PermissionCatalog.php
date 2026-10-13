<?php

namespace App\Support;

final class PermissionCatalog
{
    public static function all(): array
    {
        return [
            self::p('expenses.view', 'expenses', 'expenses', 'view'),
            self::p('expenses.create', 'expenses', 'expenses', 'create'),
            self::p('expenses.void', 'expenses', 'expenses', 'special', true),
            self::p('reports.expenses.view', 'reports', 'expenses', 'view'),
            self::p('reports.operating.view', 'reports', 'operating', 'view', true),
            self::p('catalog.items.view', 'catalog', 'items', 'view'),
            self::p('catalog.items.create', 'catalog', 'items', 'create'),
            self::p('catalog.items.update', 'catalog', 'items', 'edit'),
            self::p('catalog.items.delete', 'catalog', 'items', 'delete'),
            self::p('catalog.prices.manage', 'catalog', 'prices', 'edit', false, 'pricing.multi_channel'),
            self::p('recipes.view', 'production', 'recipes', 'view', false, 'module.production'),
            self::p('recipes.manage', 'production', 'recipes', 'edit', false, 'module.production'),
            self::p('production.orders.view', 'production', 'orders', 'view', false, 'module.production'),
            self::p('production.orders.manage', 'production', 'orders', 'edit', false, 'module.production'),
            self::p('production.runs.record', 'production', 'runs', 'edit', false, 'module.production'),
            self::p('production.waste.approve', 'production', 'waste', 'approve', false, 'module.production'),
            self::p('inventory.stock.view', 'inventory', 'stock', 'view'),
            self::p('inventory.receive', 'inventory', 'stock', 'create'),
            self::p('inventory.transfer', 'inventory', 'transfers', 'edit', false, 'branches.multi_branch'),
            self::p('inventory.adjust', 'inventory', 'adjustments', 'edit'),
            self::p('inventory.counts.approve', 'inventory', 'counts', 'approve'),
            self::p('purchasing.orders.view', 'purchasing', 'orders', 'view', false, 'module.purchasing'),
            self::p('purchasing.orders.create', 'purchasing', 'orders', 'create', false, 'module.purchasing'),
            self::p('purchasing.orders.approve', 'purchasing', 'orders', 'approve', false, 'module.purchasing'),
            self::p('purchasing.receipts.create', 'purchasing', 'receipts', 'create', false, 'module.purchasing'),
            self::p('purchasing.returns.create', 'purchasing', 'returns', 'create', false, 'module.purchasing'),
            self::p('pos.sale.create', 'pos', 'sales', 'create', false, 'module.pos'),
            self::p('pos.sale.discount', 'pos', 'discounts', 'edit', false, 'module.pos'),
            self::p('pos.sale.void', 'pos', 'voids', 'special', false, 'module.pos'),
            self::p('pos.sale.refund', 'pos', 'refunds', 'special', false, 'module.pos'),
            self::p('customers.view', 'customers', 'customers', 'view', false, 'module.customers'),
            self::p('customers.manage', 'customers', 'customers', 'edit', false, 'module.customers'),
            self::p('customers.credit', 'customers', 'credit', 'approve', false, 'customers.credit'),
            self::p('reports.sales.view', 'reports', 'sales', 'view'),
            self::p('reports.sales.export', 'reports', 'sales', 'export', true),
            self::p('reports.margin.view', 'reports', 'margin', 'view', false, 'reports.advanced'),
            self::p('reports.margin.export', 'reports', 'margin', 'export', true, 'reports.advanced'),
            self::p('reports.production.view', 'reports', 'production', 'view', false, 'module.production'),
            self::p('reports.inventory.view', 'reports', 'inventory', 'view'),
            self::p('reports.purchasing.view', 'reports', 'purchasing', 'view', false, 'module.purchasing'),
            self::p('reports.customers.view', 'reports', 'customers', 'view', false, 'module.customers'),
            self::p('reports.cash.view', 'reports', 'cash', 'view', false, 'module.pos'),
            self::p('reports.tax.view', 'reports', 'tax', 'view'),
            self::p('reports.staff.view', 'reports', 'staff', 'view'),
            self::p('reports.audit.view', 'reports', 'audit', 'view'),
            self::p('users.view', 'settings', 'users', 'view'),
            self::p('users.manage', 'settings', 'users', 'edit'),
            self::p('roles.manage', 'settings', 'roles', 'edit', true),
            self::p('system_users.manage', 'settings', 'system_users', 'edit', true, 'api.system_users'),
            self::p('audit.view', 'settings', 'audit', 'view'),
            self::p('settings.manage', 'settings', 'settings', 'edit', true),
            self::p('settings.export', 'settings', 'export', 'export', true),
            self::p('approvals.resolve', 'settings', 'approvals', 'approve'),
        ];
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $permission) {
            if ($permission['key'] === $key) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * Default scopes for non-owner roles. Missing keys mean none.
     * Owner is granted every permission at scope all.
     */
    public static function matrix(): array
    {
        $A = 'all';
        $B = 'branch';
        $O = 'own';

        return [
            'expenses.view' => ['branch_manager' => $B, 'accountant' => $A],
            'expenses.create' => ['branch_manager' => $B, 'accountant' => $A],
            'expenses.void' => ['branch_manager' => $B, 'accountant' => $A],
            'reports.expenses.view' => ['branch_manager' => $B, 'accountant' => $A],
            'reports.operating.view' => ['branch_manager' => $B, 'accountant' => $A],
            'catalog.items.view' => ['branch_manager' => $A, 'production_lead' => $A, 'cashier' => $A, 'storekeeper' => $A, 'accountant' => $A],
            'catalog.items.create' => ['branch_manager' => $B],
            'catalog.items.update' => ['branch_manager' => $B],
            'catalog.items.delete' => ['branch_manager' => $B],
            'catalog.prices.manage' => ['branch_manager' => $B],
            'recipes.view' => ['branch_manager' => $A, 'production_lead' => $A, 'accountant' => $A],
            'recipes.manage' => ['branch_manager' => $B, 'production_lead' => $B],
            'production.orders.view' => ['branch_manager' => $B, 'production_lead' => $B],
            'production.orders.manage' => ['branch_manager' => $B, 'production_lead' => $B],
            'production.runs.record' => ['branch_manager' => $B, 'production_lead' => $B],
            'production.waste.approve' => ['branch_manager' => $B],
            'inventory.stock.view' => ['branch_manager' => $B, 'production_lead' => $B, 'cashier' => $B, 'storekeeper' => $B, 'accountant' => $A],
            'inventory.receive' => ['branch_manager' => $B, 'storekeeper' => $B],
            'inventory.transfer' => ['branch_manager' => $B, 'storekeeper' => $B],
            'inventory.adjust' => ['branch_manager' => $B, 'storekeeper' => $O],
            'inventory.counts.approve' => ['branch_manager' => $B],
            'purchasing.orders.view' => ['branch_manager' => $B, 'storekeeper' => $B, 'accountant' => $A],
            'purchasing.orders.create' => ['branch_manager' => $B, 'storekeeper' => $B],
            'purchasing.orders.approve' => ['branch_manager' => $B],
            'purchasing.receipts.create' => ['branch_manager' => $B, 'storekeeper' => $B],
            'purchasing.returns.create' => ['branch_manager' => $B, 'storekeeper' => $B],
            'pos.sale.create' => ['branch_manager' => $B, 'cashier' => $O],
            'pos.sale.discount' => ['branch_manager' => $B, 'cashier' => $O],
            'pos.sale.void' => ['branch_manager' => $B, 'cashier' => $O],
            'pos.sale.refund' => ['branch_manager' => $B, 'cashier' => $O],
            'customers.view' => ['branch_manager' => $B, 'cashier' => $B, 'accountant' => $A],
            'customers.manage' => ['branch_manager' => $B, 'cashier' => $B, 'accountant' => $A],
            'customers.credit' => ['branch_manager' => $B, 'accountant' => $A],
            'reports.sales.view' => ['branch_manager' => $B, 'cashier' => $O, 'accountant' => $A],
            'reports.sales.export' => ['branch_manager' => $B, 'accountant' => $A],
            'reports.margin.view' => ['branch_manager' => $B, 'production_lead' => $B, 'accountant' => $A],
            'reports.margin.export' => ['branch_manager' => $B, 'accountant' => $A],
            'reports.production.view' => ['branch_manager' => $B, 'production_lead' => $B, 'accountant' => $A],
            'reports.inventory.view' => ['branch_manager' => $B, 'production_lead' => $B, 'storekeeper' => $B, 'accountant' => $A],
            'reports.purchasing.view' => ['branch_manager' => $B, 'storekeeper' => $B, 'accountant' => $A],
            'reports.customers.view' => ['branch_manager' => $B, 'accountant' => $A],
            'reports.cash.view' => ['branch_manager' => $B, 'accountant' => $A],
            'reports.tax.view' => ['branch_manager' => $B, 'accountant' => $A],
            'reports.staff.view' => ['branch_manager' => $B, 'accountant' => $A],
            'reports.audit.view' => ['branch_manager' => $B, 'accountant' => $A],
            'users.view' => ['branch_manager' => $B],
            'users.manage' => ['branch_manager' => $B],
            'audit.view' => ['branch_manager' => $B, 'accountant' => $A],
            'approvals.resolve' => ['branch_manager' => $B],
        ];
    }

    public static function constraints(): array
    {
        return [
            'pos.sale.discount' => [
                'cashier' => ['max_discount_percent' => 5],
                'branch_manager' => ['max_discount_percent' => 30],
            ],
            'pos.sale.void' => [
                'cashier' => ['requires_approval' => true],
            ],
            'pos.sale.refund' => [
                'cashier' => ['requires_approval' => true, 'max_refund_amount' => 50000],
                'branch_manager' => ['max_refund_amount' => 1000000],
            ],
            'inventory.adjust' => [
                'storekeeper' => ['max_adjustment_value' => 50000],
                'branch_manager' => ['max_adjustment_value' => 500000],
            ],
            'purchasing.orders.approve' => [
                'branch_manager' => ['max_po_amount' => 5000000],
            ],
        ];
    }

    public static function roles(): array
    {
        return [
            'owner' => ['name' => 'Owner', 'locked' => true],
            'branch_manager' => ['name' => 'Branch Manager', 'locked' => false],
            'production_lead' => ['name' => 'Production Lead', 'locked' => false],
            'cashier' => ['name' => 'Cashier', 'locked' => false],
            'storekeeper' => ['name' => 'Storekeeper', 'locked' => false],
            'accountant' => ['name' => 'Accountant', 'locked' => false],
        ];
    }

    private static function p(string $key, string $module, string $resource, string $action, bool $sensitive = false, ?string $feature = null): array
    {
        return [
            'key' => $key,
            'module' => $module,
            'resource' => $resource,
            'action' => $action,
            'sensitive' => $sensitive,
            'feature' => $feature,
            'description' => $key,
        ];
    }
}
