<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->decimal('rate', 8, 4);
            $table->string('category')->default('standard');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->boolean('requires_reference')->default(false);
            $table->timestamps();
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->index();
            $table->string('document_type');
            $table->string('prefix');
            $table->unsignedInteger('next_number')->default(1);
            $table->unique(['branch_id', 'document_type']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('employee_code')->nullable();
            $table->string('job_title')->nullable();
            $table->string('pin_hash')->nullable();
            $table->string('status')->default('invited');
            $table->string('theme')->default('system');
            $table->string('locale')->nullable();
            $table->string('invite_token')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('permission_version')->default(1);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('module');
            $table->string('resource');
            $table->string('action');
            $table->boolean('sensitive')->default(false);
            $table->string('feature')->nullable();
            $table->string('description')->nullable();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('role_id')->index();
            $table->string('permission_key');
            $table->string('scope')->default('none');
            $table->json('constraints')->nullable();
            $table->unique(['role_id', 'permission_key']);
        });

        Schema::create('role_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('role_id')->index();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('user_branch_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('role_id')->index();
            $table->unique(['user_id', 'branch_id']);
        });

        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('permission_key');
            $table->string('effect');
            $table->string('scope')->default('branch');
            $table->json('constraints')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->string('permission_key')->nullable();
            $table->json('payload');
            $table->uuid('requested_by')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('actor_type')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->uuid('on_behalf_of')->nullable();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('active_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('session_id')->index();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('remember_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('selector')->unique();
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('name_alt')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->string('color')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('symbol')->unique();
            $table->timestamps();
        });

        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('from_unit_id');
            $table->uuid('to_unit_id');
            $table->decimal('factor', 18, 6);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('name');
            $table->string('name_alt')->nullable();
            $table->string('sku')->unique();
            $table->uuid('category_id')->nullable()->index();
            $table->uuid('base_unit_id')->nullable();
            $table->uuid('purchase_unit_id')->nullable();
            $table->uuid('sales_unit_id')->nullable();
            $table->uuid('tax_rate_id')->nullable();
            $table->boolean('track_stock')->default(true);
            $table->string('make_mode')->default('make_to_stock');
            $table->decimal('reorder_level', 18, 4)->default(0);
            $table->decimal('default_price', 18, 4)->default(0);
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('negative_stock_policy')->default('inherit');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('item_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->index();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->decimal('price_delta', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('item_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->index();
            $table->uuid('variant_id')->nullable();
            $table->string('barcode')->unique();
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('channel')->default('retail');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('price_list_id')->index();
            $table->uuid('item_id')->index();
            $table->uuid('variant_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->decimal('price', 18, 4);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
        });

        Schema::create('custom_field_defs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('item_type');
            $table->string('key');
            $table->string('label');
            $table->string('field_type');
            $table->json('options')->nullable();
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->index();
            $table->uuid('custom_field_def_id');
            $table->text('value')->nullable();
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->index();
            $table->uuid('branch_id')->index();
            $table->string('lot_code');
            $table->date('expiry_date')->nullable();
            $table->decimal('qty', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id');
            $table->uuid('branch_id');
            $table->uuid('lot_id')->nullable();
            $table->decimal('qty', 18, 4)->default(0);
            $table->decimal('value', 18, 4)->default(0);
            $table->decimal('avg_cost', 18, 4)->default(0);
            $table->index(['item_id', 'branch_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id');
            $table->uuid('branch_id');
            $table->uuid('lot_id')->nullable();
            $table->string('type');
            $table->decimal('quantity', 18, 4);
            $table->decimal('signed_qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->uuid('user_id')->nullable();
            $table->uuid('system_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['item_id', 'branch_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->uuid('from_branch_id')->index();
            $table->uuid('to_branch_id')->index();
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transfer_id')->index();
            $table->uuid('item_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_received', 18, 4)->default(0);
            $table->uuid('lot_id')->nullable();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->uuid('branch_id')->index();
            $table->string('type')->default('cycle');
            $table->boolean('blind')->default(false);
            $table->string('status')->default('draft');
            $table->uuid('counted_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_count_id')->index();
            $table->uuid('item_id');
            $table->decimal('expected_qty', 18, 4)->default(0);
            $table->decimal('counted_qty', 18, 4)->nullable();
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->index();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id')->index();
            $table->unsignedInteger('version');
            $table->decimal('yield_qty', 18, 4);
            $table->decimal('wastage_percent', 8, 4)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipe_version_id')->index();
            $table->uuid('item_id');
            $table->decimal('qty', 18, 4);
            $table->uuid('unit_id')->nullable();
            $table->decimal('wastage_percent', 8, 4)->default(0);
        });

        Schema::create('recipe_cost_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipe_version_id')->index();
            $table->string('type');
            $table->string('name');
            $table->decimal('amount', 18, 4);
        });

        Schema::create('recipe_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipe_version_id')->index();
            $table->string('name');
            $table->unsignedInteger('position');
            $table->boolean('requires_quality')->default(false);
        });

        Schema::create('production_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->uuid('branch_id')->index();
            $table->uuid('recipe_id');
            $table->uuid('recipe_version_id');
            $table->decimal('planned_qty', 18, 4);
            $table->date('due_date')->nullable();
            $table->uuid('assignee_id')->nullable();
            $table->string('priority')->default('normal');
            $table->string('status')->default('draft');
            $table->uuid('sale_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('production_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_order_id')->index();
            $table->string('status')->default('in_progress');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->decimal('labor_hours', 18, 4)->default(0);
            $table->decimal('labor_cost', 18, 4)->default(0);
            $table->decimal('overhead_cost', 18, 4)->default(0);
            $table->decimal('material_cost', 18, 4)->default(0);
            $table->decimal('batch_cost', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('good_qty', 18, 4)->default(0);
            $table->decimal('waste_qty', 18, 4)->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('run_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_run_id')->index();
            $table->uuid('item_id');
            $table->decimal('planned_qty', 18, 4);
            $table->decimal('actual_qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
        });

        Schema::create('run_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_run_id')->index();
            $table->uuid('item_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->uuid('lot_id')->nullable();
        });

        Schema::create('run_waste', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_run_id')->index();
            $table->uuid('item_id');
            $table->decimal('qty', 18, 4);
            $table->string('reason')->nullable();
            $table->string('stage_name')->nullable();
        });

        Schema::create('run_stage_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_run_id')->index();
            $table->uuid('recipe_stage_id')->nullable();
            $table->string('name');
            $table->string('status')->default('pending');
            $table->uuid('assignee_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('quality_passed')->nullable();
            $table->text('notes')->nullable();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('payment_terms')->nullable();
            $table->decimal('balance', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('supplier_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id')->index();
            $table->uuid('item_id')->index();
            $table->decimal('price', 18, 4);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->uuid('supplier_id')->index();
            $table->uuid('branch_id')->index();
            $table->string('status')->default('draft');
            $table->date('expected_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id')->index();
            $table->uuid('item_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('qty_received', 18, 4)->default(0);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->uuid('purchase_order_id')->nullable()->index();
            $table->uuid('supplier_id')->nullable();
            $table->uuid('branch_id')->index();
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('goods_receipt_id')->index();
            $table->uuid('purchase_order_line_id')->nullable();
            $table->uuid('item_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->string('lot_code')->nullable();
            $table->date('expiry_date')->nullable();
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->uuid('supplier_id');
            $table->uuid('branch_id');
            $table->uuid('item_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->text('notes')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('group_name')->nullable();
            $table->uuid('price_list_id')->nullable();
            $table->decimal('credit_limit', 18, 4)->default(0);
            $table->decimal('balance', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->index();
            $table->decimal('amount', 18, 4);
            $table->string('method');
            $table->string('reference')->nullable();
            $table->uuid('sale_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('terminals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->index();
            $table->string('name');
            $table->unsignedSmallInteger('paper_width')->default(80);
            $table->string('forced_theme')->nullable();
            $table->string('last_locale')->nullable();
            $table->timestamps();
        });

        Schema::create('till_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->index();
            $table->uuid('terminal_id')->nullable();
            $table->uuid('user_id')->index();
            $table->decimal('opening_float', 18, 4)->default(0);
            $table->string('status')->default('open');
            $table->decimal('expected_cash', 18, 4)->default(0);
            $table->decimal('counted_cash', 18, 4)->nullable();
            $table->decimal('variance', 18, 4)->nullable();
            $table->text('variance_reason')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('till_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('till_session_id')->index();
            $table->string('type');
            $table->decimal('amount', 18, 4);
            $table->text('reason')->nullable();
            $table->uuid('sale_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number')->nullable();
            $table->uuid('branch_id')->index();
            $table->uuid('terminal_id')->nullable();
            $table->uuid('till_session_id')->nullable();
            $table->uuid('user_id')->index();
            $table->uuid('customer_id')->nullable();
            $table->uuid('price_list_id')->nullable();
            $table->string('status')->default('completed');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('offline_ref')->nullable();
            $table->boolean('prices_include_tax')->default(true);
            $table->timestamps();
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sale_id')->index();
            $table->uuid('item_id');
            $table->uuid('variant_id')->nullable();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('net', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('gross', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->uuid('lot_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('fulfillment')->default('stock');
            $table->uuid('production_order_id')->nullable();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sale_id')->index();
            $table->string('method');
            $table->decimal('amount', 18, 4);
            $table->string('reference')->nullable();
        });

        Schema::create('returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->uuid('sale_id')->index();
            $table->uuid('branch_id');
            $table->text('reason');
            $table->boolean('restock')->default(true);
            $table->uuid('user_id')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamps();
        });

        Schema::create('return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('return_id')->index();
            $table->uuid('sale_line_id');
            $table->decimal('qty', 18, 4);
            $table->boolean('restock')->default(true);
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sale_id')->index();
            $table->string('number');
            $table->uuid('branch_id');
            $table->unsignedInteger('reprint_count')->default(0);
            $table->string('language')->default('bilingual');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('system_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('purpose')->nullable();
            $table->uuid('owner_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('system_user_id')->index();
            $table->string('name');
            $table->string('token_hash');
            $table->string('token_prefix');
            $table->json('scopes');
            $table->json('branch_ids')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('ip_allowlist')->nullable();
            $table->unsignedInteger('rate_limit')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('locale');
            $table->string('key');
            $table->text('value');
            $table->unique(['locale', 'key']);
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('url');
            $table->json('events');
            $table->string('secret');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('webhook_id')->index();
            $table->string('event');
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('pos_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('terminal_key')->nullable();
            $table->string('name')->nullable();
            $table->json('cart');
            $table->timestamps();
        });

        Schema::create('targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->string('metric');
            $table->string('period');
            $table->decimal('target_amount', 18, 4);
            $table->timestamps();
        });

        Schema::create('report_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('report_key');
            $table->string('frequency');
            $table->json('filters')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('report_key');
            $table->string('name');
            $table->json('filters');
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX stock_balances_item_branch_lot_uidx ON stock_balances (item_id, branch_id, lot_id) WHERE lot_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX stock_balances_item_branch_null_lot_uidx ON stock_balances (item_id, branch_id) WHERE lot_id IS NULL');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION makerly_stock_movements_immutable()
RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'stock movements are append-only';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER stock_movements_immutable
BEFORE UPDATE OR DELETE ON stock_movements
FOR EACH ROW EXECUTE FUNCTION makerly_stock_movements_immutable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_immutable ON stock_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS makerly_stock_movements_immutable()');
        foreach ([
            'report_views', 'report_subscriptions', 'targets', 'pos_drafts', 'webhook_deliveries', 'webhooks',
            'translation_overrides', 'notifications', 'api_tokens', 'system_users', 'receipts', 'return_lines',
            'returns', 'sale_payments', 'sale_lines', 'sales', 'till_movements', 'till_sessions', 'terminals',
            'customer_payments', 'customers', 'supplier_returns', 'goods_receipt_lines', 'goods_receipts',
            'purchase_order_lines', 'purchase_orders', 'supplier_prices', 'suppliers', 'run_stage_logs',
            'run_waste', 'run_outputs', 'run_inputs', 'production_runs', 'production_orders', 'recipe_stages',
            'recipe_cost_lines', 'recipe_lines', 'recipe_versions', 'recipes', 'stock_count_lines', 'stock_counts',
            'transfer_lines', 'transfers', 'stock_movements', 'stock_balances', 'stock_lots', 'custom_field_values',
            'custom_field_defs', 'price_list_items', 'price_lists', 'item_barcodes', 'item_variants', 'items',
            'unit_conversions', 'units', 'categories', 'remember_devices', 'active_sessions', 'audit_logs',
            'approval_requests', 'user_permission_overrides', 'user_branch_roles', 'role_versions', 'role_permissions',
            'permissions', 'roles', 'password_reset_tokens', 'users', 'number_sequences', 'payment_methods',
            'tax_rates', 'settings', 'branches',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
