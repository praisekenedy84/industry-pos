<?php

namespace App\Tenancy;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Identity;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionLine;
use App\Models\Recipe;
use App\Models\RecipeCostLine;
use App\Models\RecipeLine;
use App\Models\RecipeStage;
use App\Models\RecipeVersion;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\TenantLimit;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Models\Workspace;
use App\Services\StockLedger;
use App\Support\Bc;
use App\Support\FeatureCatalog;
use App\Support\IndustryCatalog;
use App\Support\IndustryVocabulary;
use App\Support\PermissionCatalog;
use App\Support\WorkspaceApproval;
use App\Support\WorkspaceSlug;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantProvisioner
{
    public function __construct(private TenancyManager $tenancy) {}

    public function provision(array $input): Tenant
    {
        $slug = WorkspaceSlug::assert($input['slug']);

        $schema = 't_'.str_replace('-', '_', $slug);
        $trial = (bool) ($input['trial'] ?? false);
        $tenant = Tenant::query()->create([
            'name' => $input['name'],
            'slug' => $slug,
            'schema_name' => $schema,
            'status' => $trial ? 'trial' : 'active',
            'industry_key' => $input['industry_key'] ?? 'bakery',
            'default_locale' => $input['default_locale'] ?? 'en',
            'receipt_language' => $input['receipt_language'] ?? 'bilingual',
            'accent_color' => $input['accent_color'] ?? '#0075de',
            'timezone' => 'Africa/Dar_es_Salaam',
            'owner_email' => $input['owner_email'],
            'trial_ends_at' => $trial ? now()->addDays(WorkspaceApproval::trialDays()) : null,
            'approved_at' => $trial ? null : now(),
        ]);

        Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => $slug,
            'display_name' => $input['name'],
            'status' => 'active',
        ]);

        foreach (FeatureCatalog::all() as $feature) {
            Feature::query()->updateOrCreate(['key' => $feature['key']], [
                'type' => 'boolean',
                'default_value' => $feature['default_value'],
                'module' => $feature['module'],
                'description' => $feature['description'],
                'globally_enabled' => true,
            ]);
            TenantFeature::query()->create([
                'tenant_id' => $tenant->id,
                'feature_key' => $feature['key'],
                'value' => array_key_exists('features', $input) ? (bool) ($input['features'][$feature['key']] ?? true) : true,
            ]);
        }

        foreach (FeatureCatalog::limits() as $key => $value) {
            TenantLimit::query()->create([
                'tenant_id' => $tenant->id,
                'limit_key' => $key,
                'value' => $input['limits'][$key] ?? $value,
            ]);
        }

        DB::connection('central')->statement('CREATE SCHEMA IF NOT EXISTS '.$schema);
        config(['database.connections.central.search_path' => $schema]);
        DB::purge('central');
        try {
            Artisan::call('migrate', [
                '--database' => 'central',
                '--path' => 'database/tenant',
                '--force' => true,
            ]);
        } finally {
            config(['database.connections.central.search_path' => 'public']);
            DB::purge('central');
            DB::reconnect('central');
        }

        $this->tenancy->initialize($tenant->fresh());
        $this->seedFoundation($input);

        return $tenant->fresh('workspace');
    }

    public function seedFoundation(array $input): array
    {
        foreach (PermissionCatalog::all() as $permission) {
            Permission::query()->updateOrCreate(['key' => $permission['key']], $permission);
        }

        $roles = [];
        foreach (PermissionCatalog::roles() as $slug => $meta) {
            $role = Role::query()->create([
                'name' => $meta['name'],
                'slug' => $slug,
                'is_system' => true,
                'is_locked' => $meta['locked'],
            ]);
            $roles[$slug] = $role;
            if ($slug === 'owner') {
                foreach (PermissionCatalog::all() as $permission) {
                    RolePermission::query()->create([
                        'role_id' => $role->id,
                        'permission_key' => $permission['key'],
                        'scope' => 'all',
                    ]);
                }

                continue;
            }
            foreach (PermissionCatalog::matrix() as $key => $grants) {
                if (! isset($grants[$slug])) {
                    continue;
                }
                RolePermission::query()->create([
                    'role_id' => $role->id,
                    'permission_key' => $key,
                    'scope' => $grants[$slug],
                    'constraints' => PermissionCatalog::constraints()[$key][$slug] ?? null,
                ]);
            }
        }

        TaxRate::query()->create(['name' => 'VAT 18%', 'rate' => 18, 'category' => 'standard', 'is_default' => true]);
        TaxRate::query()->create(['name' => 'Zero rated', 'rate' => 0, 'category' => 'zero', 'is_default' => false]);
        TaxRate::query()->create(['name' => 'Exempt', 'rate' => 0, 'category' => 'exempt', 'is_default' => false]);

        foreach ([
            ['cash', 'Cash', false],
            ['card', 'Card', true],
            ['mobile_money', 'Mobile money', true],
            ['bank_transfer', 'Bank transfer', true],
            ['store_credit', 'Store credit', false],
            ['account', 'On account', false],
        ] as [$key, $name, $reference]) {
            PaymentMethod::query()->create([
                'key' => $key,
                'name' => $name,
                'enabled' => true,
                'requires_reference' => $reference,
            ]);
        }

        $industry = IndustryCatalog::find($input['industry_key'] ?? 'bakery');
        Setting::putValue('prices_include_tax', true);
        Setting::putValue('negative_stock', 'block');
        Setting::putValue('valuation', 'weighted_average');
        Setting::putValue('idle_timeout', 30);
        Setting::putValue('pos_idle_timeout', 15);
        Setting::putValue('absolute_session_hours', 12);
        Setting::putValue('require_2fa_roles', []);
        Setting::putValue('vocabulary', app(IndustryVocabulary::class)->forIndustry($input['industry_key'] ?? 'bakery'));
        Setting::putValue('receipt_footer', 'Asante / Thank you');
        Setting::putValue('max_sessions', ['cashier' => 1]);
        Setting::putValue('unlock_method', 'password');
        Setting::putValue('offline_max_hours', 12);
        Setting::putValue('pwa_display', 'standalone');
        Setting::putValue('onboarding_dismissed', false);

        $branch = Branch::query()->create([
            'name' => $input['branch_name'] ?? 'Main branch',
            'code' => $input['branch_code'] ?? 'MAIN',
            'status' => 'active',
            'address' => $input['branch_address'] ?? null,
            'phone' => $input['branch_phone'] ?? null,
        ]);

        $owner = User::query()->create([
            'name' => $input['owner_name'],
            'email' => $input['owner_email'],
            'phone' => $input['owner_phone'] ?? null,
            'password' => $input['owner_password'],
            'status' => 'active',
            'job_title' => 'Owner',
            'joined_at' => now(),
            'locale' => $input['default_locale'] ?? 'en',
        ]);
        UserBranchRole::query()->create([
            'user_id' => $owner->id,
            'branch_id' => $branch->id,
            'role_id' => $roles['owner']->id,
        ]);
        $this->attachIdentity($owner, $input['owner_password'] ?? null);

        if ($input['sample'] ?? true) {
            $this->seedSample($industry, $branch, $owner);
        }

        return ['branch' => $branch, 'owner' => $owner, 'roles' => $roles];
    }

    public function seedSample(array $industry, Branch $branch, User $owner): void
    {
        $units = [];
        foreach ($industry['units'] as $unit) {
            $units[$unit['symbol']] = Unit::query()->firstOrCreate(
                ['symbol' => $unit['symbol']],
                ['name' => $unit['name']],
            );
        }
        foreach ($industry['conversions'] as $conversion) {
            UnitConversion::query()->firstOrCreate([
                'from_unit_id' => $units[$conversion['from']]->id,
                'to_unit_id' => $units[$conversion['to']]->id,
            ], ['factor' => $conversion['factor']]);
        }
        $categories = [];
        foreach ($industry['categories'] as $category) {
            $categories[$category['name']] = Category::query()->firstOrCreate(
                ['name' => $category['name']],
                ['name_alt' => $category['name_alt'], 'color' => $category['color']],
            );
        }
        $tax = TaxRate::query()->where('is_default', true)->first();
        $list = PriceList::query()->firstOrCreate(
            ['channel' => 'retail'],
            ['name' => 'Retail', 'is_default' => true],
        );
        $items = [];
        $ledger = app(StockLedger::class);
        foreach ($industry['items'] as $row) {
            $unit = $units[$row['unit']] ?? reset($units);
            $item = Item::query()->create([
                'type' => $row['type'],
                'name' => $row['name'],
                'name_alt' => $row['name_alt'],
                'sku' => $row['sku'],
                'category_id' => $categories[$row['category']]->id ?? null,
                'base_unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sales_unit_id' => $unit->id,
                'tax_rate_id' => $tax?->id,
                'track_stock' => $row['type'] !== 'service',
                'make_mode' => 'make_to_stock',
                'reorder_level' => $row['type'] === 'raw_material' ? 5 : 0,
                'default_price' => $row['price'],
                'created_by' => $owner->id,
            ]);
            $items[$row['sku']] = $item;
            if (Bc::cmp($row['price'], '0') > 0) {
                PriceListItem::query()->create([
                    'price_list_id' => $list->id,
                    'item_id' => $item->id,
                    'price' => $row['price'],
                ]);
            }
            if (Bc::cmp($row['qty'], '0') > 0) {
                $ledger->post([
                    'item_id' => $item->id,
                    'branch_id' => $branch->id,
                    'type' => 'opening_balance',
                    'quantity' => $row['qty'],
                    'signed_qty' => $row['qty'],
                    'unit_cost' => $row['opening_cost'] ?? $row['cost'],
                    'user_id' => $owner->id,
                    'reason' => 'Opening balance',
                    'reference_type' => 'opening_balance',
                    'reference_id' => $item->id,
                ]);
            }
        }

        $recipeDef = $industry['recipe'];
        $output = $items[$recipeDef['output']] ?? null;
        if (! $output) {
            return;
        }
        $recipe = Recipe::query()->create([
            'item_id' => $output->id,
            'name' => $recipeDef['name'],
            'current_version' => 1,
        ]);
        ProductionLine::query()->create([
            'branch_id' => $branch->id,
            'recipe_id' => $recipe->id,
            'name' => $recipe->name.' line',
            'code' => 'LINE-'.substr($recipe->id, 0, 8),
            'status' => 'active',
        ]);
        $version = RecipeVersion::query()->create([
            'recipe_id' => $recipe->id,
            'version' => 1,
            'yield_qty' => $recipeDef['yield'],
            'created_by' => $owner->id,
        ]);
        foreach ($recipeDef['lines'] as $line) {
            RecipeLine::query()->create([
                'recipe_version_id' => $version->id,
                'item_id' => $items[$line['sku']]->id,
                'qty' => $line['qty'],
                'unit_id' => $items[$line['sku']]->base_unit_id,
            ]);
        }
        RecipeCostLine::query()->create([
            'recipe_version_id' => $version->id,
            'type' => 'labor',
            'name' => 'Labor',
            'amount' => $recipeDef['labor'],
        ]);
        RecipeCostLine::query()->create([
            'recipe_version_id' => $version->id,
            'type' => 'overhead',
            'name' => 'Overhead',
            'amount' => $recipeDef['overhead'],
        ]);
        foreach ($recipeDef['stages'] ?? [] as $index => $stage) {
            RecipeStage::query()->create([
                'recipe_version_id' => $version->id,
                'name' => $stage,
                'position' => $index + 1,
                'requires_quality' => $stage === 'Bake',
            ]);
        }
    }

    public function inviteUser(array $data, string $roleId, string $branchId): User
    {
        $token = Str::random(48);
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'employee_code' => $data['employee_code'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'status' => $data['password'] ?? null ? 'active' : 'invited',
            'password' => $data['password'] ?? null,
            'invite_token' => $data['password'] ?? null ? null : hash('sha256', $token),
            'invited_at' => now(),
            'joined_at' => $data['password'] ?? null ? now() : null,
            'pin_hash' => ! empty($data['pin']) ? Hash::make($data['pin']) : null,
        ]);
        UserBranchRole::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'role_id' => $roleId,
        ]);
        $tenantId = app(TenancyManager::class)->id();
        $workspace = Workspace::query()->where('tenant_id', $tenantId)->first();
        if (! empty($data['username']) && ! empty($data['password'])) {
            $alias = strtolower(($workspace?->slug ?: 'workspace').':'.$data['username']);
            $identity = Identity::query()->create([
                'login_alias' => $alias,
                'password' => $data['password'],
                'status' => 'active',
                'must_change_password' => true,
            ]);
            $membership = Membership::query()->create([
                'identity_id' => $identity->id,
                'tenant_id' => $tenantId,
                'tenant_user_id' => $user->id,
                'status' => 'active',
            ]);
            $user->forceFill(['membership_id' => $membership->id, 'status' => 'active', 'joined_at' => now()])->save();
        } elseif (! empty($data['password']) && $user->email) {
            $this->attachIdentity($user, $data['password']);
        } else {
            Invitation::query()->create([
                'tenant_id' => $tenantId,
                'tenant_user_id' => $user->id,
                'name' => $data['name'],
                'email' => isset($data['email']) ? strtolower((string) $data['email']) : null,
                'phone' => $data['phone'] ?? null,
                'role_id' => $roleId,
                'branch_ids' => [$branchId],
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ]);
        }
        $user->plain_invite_token = $token;

        return $user;
    }

    private function attachIdentity(User $user, ?string $password): void
    {
        $tenantId = app(TenancyManager::class)->id();
        $email = $user->email ? strtolower($user->email) : null;
        $identity = $email ? Identity::query()->where('email', $email)->first() : null;
        if (! $identity) {
            $identity = Identity::query()->create([
                'email' => $email,
                'phone' => $user->phone,
                'password' => $password ?: $user->password,
                'status' => 'active',
                'locale' => $user->locale,
                'theme' => $user->theme ?: 'system',
            ]);
        }
        $membership = Membership::query()->create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenantId,
            'tenant_user_id' => $user->id,
            'status' => 'active',
            'last_used_at' => now(),
        ]);
        $user->forceFill(['membership_id' => $membership->id])->save();
    }
}
