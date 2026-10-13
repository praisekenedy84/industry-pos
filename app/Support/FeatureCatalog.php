<?php

namespace App\Support;

final class FeatureCatalog
{
    public static function all(): array
    {
        return [
            self::flag('module.production', 'Production', 'Recipes, production orders, runs and costing.'),
            self::flag('module.pos', 'Point of sale', 'Sales, payments, receipts and returns.'),
            self::flag('module.purchasing', 'Purchasing', 'Suppliers, purchase orders and goods receipts.'),
            self::flag('module.customers', 'Customers', 'Customer profiles and statements.'),
            self::flag('pos.offline_mode', 'Offline POS', 'Queue sales on the terminal when the network drops.'),
            self::flag('production.multi_stage', 'Multi-stage production', 'Ordered stages and quality checks on a recipe.'),
            self::flag('inventory.lots_expiry', 'Lots and expiry', 'Lot numbers, expiry dates and FEFO picking.'),
            self::flag('customers.credit', 'Customer credit', 'Credit limits, on-account sales and ageing.'),
            self::flag('api.system_users', 'System users', 'Scoped API tokens for integrations.'),
            self::flag('reports.advanced', 'Advanced reports', 'Margin, yield, ageing and management reports.'),
            self::flag('pricing.multi_channel', 'Price lists', 'Retail, wholesale and branch price lists.'),
            self::flag('branches.multi_branch', 'Multiple branches', 'More than one branch, transfers and the branch switcher.'),
            self::flag('api.webhooks', 'Webhooks', 'Signed webhooks for sales, production and low stock.'),
        ];
    }

    public static function limits(): array
    {
        return [
            'max_branches' => 3,
            'max_staff_users' => 25,
            'max_system_users' => 5,
            'max_items' => 2000,
            'max_monthly_transactions' => 20000,
        ];
    }

    private static function flag(string $key, string $module, string $description): array
    {
        return [
            'key' => $key,
            'type' => 'boolean',
            'default_value' => true,
            'description' => $description,
            'module' => $module,
        ];
    }
}
