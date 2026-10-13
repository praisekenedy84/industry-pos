<?php

namespace Database\Seeders;

use App\Models\IndustryTemplate;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Support\IndustryCatalog;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (IndustryCatalog::all() as $key => $payload) {
            IndustryTemplate::query()->updateOrCreate(
                ['key' => $key],
                ['name' => $payload['name'], 'payload' => $payload],
            );
        }

        PlatformAdmin::query()->updateOrCreate(
            ['email' => 'admin@makerly.test'],
            ['name' => 'Makerly Admin', 'password' => 'Makerly#2026'],
        );

        if (\App\Models\Tenant::query()->where('slug', 'acme')->exists()) {
            return;
        }

        $tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Acme Bakery',
            'slug' => 'acme',
            'domain' => 'acme.localhost',
            'industry_key' => 'bakery',
            'owner_name' => 'Amina Bakari',
            'owner_email' => 'owner@acme.test',
            'owner_password' => 'Makerly#2026',
            'branch_name' => 'Kariakoo',
            'branch_code' => 'KRK',
            'sample' => true,
            'default_locale' => 'en',
        ]);

        app(TenancyManager::class)->initialize($tenant);
        $branch = \App\Models\Branch::query()->first();
        $roles = Role::query()->get()->keyBy('slug');
        $provisioner = app(TenantProvisioner::class);

        $provisioner->inviteUser([
            'name' => 'Neema Cashier',
            'email' => 'cashier@acme.test',
            'password' => 'Makerly#2026',
            'job_title' => 'Cashier',
            'pin' => '1234',
        ], $roles['cashier']->id, $branch->id);

        $provisioner->inviteUser([
            'name' => 'Juma Production',
            'email' => 'production@acme.test',
            'password' => 'Makerly#2026',
            'job_title' => 'Production lead',
            'pin' => '2345',
        ], $roles['production_lead']->id, $branch->id);

        $provisioner->inviteUser([
            'name' => 'Baraka Stores',
            'email' => 'store@acme.test',
            'password' => 'Makerly#2026',
            'job_title' => 'Storekeeper',
            'pin' => '3456',
        ], $roles['storekeeper']->id, $branch->id);

        User::query()->where('email', 'owner@acme.test')->update(['pin_hash' => Hash::make('2468')]);
    }
}
