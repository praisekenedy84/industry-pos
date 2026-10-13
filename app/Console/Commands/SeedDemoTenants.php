<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\IndustryCatalog;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;

class SeedDemoTenants extends Command
{
    /**
     * @var list<array{industry_key: string, name: string, slug: string, owner_name: string, owner_email: string, branch_name: string, branch_code: string}>
     */
    private const DEMOS = [
        [
            'industry_key' => 'brick',
            'name' => 'Brick making demo',
            'slug' => 'demo-brick',
            'owner_name' => 'Brick Demo Owner',
            'owner_email' => 'owner@demo-brick.test',
            'branch_name' => 'Main yard',
            'branch_code' => 'BRK',
        ],
        [
            'industry_key' => 'furniture',
            'name' => 'Furniture demo',
            'slug' => 'demo-furniture',
            'owner_name' => 'Furniture Demo Owner',
            'owner_email' => 'owner@demo-furniture.test',
            'branch_name' => 'Main workshop',
            'branch_code' => 'FUR',
        ],
        [
            'industry_key' => 'general',
            'name' => 'General manufacturing demo',
            'slug' => 'demo-general',
            'owner_name' => 'General Demo Owner',
            'owner_email' => 'owner@demo-general.test',
            'branch_name' => 'Main plant',
            'branch_code' => 'GEN',
        ],
        [
            'industry_key' => 'construction',
            'name' => 'Construction demo',
            'slug' => 'demo-construction',
            'owner_name' => 'Construction Demo Owner',
            'owner_email' => 'owner@demo-construction.test',
            'branch_name' => 'Main site',
            'branch_code' => 'CON',
        ],
    ];

    protected $signature = 'makerly:seed-demo-tenants
        {--force : Required when the application environment is production}
        {--password=Makerly#2026 : Owner password for each new workspace}';

    protected $description = 'Create approved demo workspaces for brick making, furniture, general manufacturing, and construction';

    public function handle(TenantProvisioner $provisioner, TenancyManager $tenancy): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Pass --force to create demo workspaces in production.');

            return self::FAILURE;
        }

        $password = (string) $this->option('password');
        if (strlen($password) < 10) {
            $this->error('The owner password must be at least 10 characters.');

            return self::FAILURE;
        }

        $catalog = IndustryCatalog::all();
        foreach (self::DEMOS as $demo) {
            if (! isset($catalog[$demo['industry_key']])) {
                $this->error('Unknown industry '.$demo['industry_key'].'.');

                return self::FAILURE;
            }
        }

        $existing = Tenant::query()->whereIn('slug', array_column(self::DEMOS, 'slug'))->get()->keyBy('slug');
        foreach (self::DEMOS as $demo) {
            $tenant = $existing->get($demo['slug']);
            if ($tenant && $tenant->industry_key !== $demo['industry_key']) {
                $this->error($demo['slug'].' already belongs to '.$tenant->industry_key.'.');

                return self::FAILURE;
            }
        }

        $previous = $tenancy->tenant();
        $created = 0;
        $rows = [];
        try {
            foreach (self::DEMOS as $demo) {
                $tenancy->end();
                if ($existing->has($demo['slug'])) {
                    $rows[] = [$demo['slug'], IndustryCatalog::name($demo['industry_key']), '/w/'.$demo['slug'].'/login', $demo['owner_email'], 'already exists'];

                    continue;
                }

                $tenant = $provisioner->provision([
                    ...$demo,
                    'owner_password' => $password,
                    'sample' => true,
                    'trial' => false,
                ]);
                $created++;
                $rows[] = [$tenant->slug, IndustryCatalog::name($demo['industry_key']), '/w/'.$tenant->slug.'/login', $demo['owner_email'], 'created'];
            }
        } finally {
            $tenancy->end();
            if ($previous) {
                $tenancy->initialize($previous);
            }
        }

        $this->table(['Workspace', 'Industry', 'Sign in', 'Owner', 'Result'], $rows);
        if ($created > 0) {
            $this->line('Owner password for the new workspaces: '.$password);
        }

        return self::SUCCESS;
    }
}
