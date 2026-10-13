<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class TenancyManager
{
    private ?Tenant $tenant = null;

    public function initialize(Tenant $tenant): void
    {
        $schema = $tenant->schema_name;
        if (! preg_match('/^[a-z][a-z0-9_]+$/', $schema)) {
            throw new \RuntimeException('Invalid tenant schema name.');
        }

        config(['database.connections.tenant.search_path' => $schema]);
        DB::purge('tenant');
        DB::reconnect('tenant');
        DB::connection('tenant')->statement('SET search_path TO '.$schema);

        $this->tenant = $tenant;
        app()->instance('makerly.timezone', $tenant->timezone ?: config('makerly.timezone'));
    }

    public function end(): void
    {
        $this->tenant = null;
        config(['database.connections.tenant.search_path' => 'public']);
        DB::purge('tenant');
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function initialized(): bool
    {
        return $this->tenant !== null;
    }

    public function id(): ?string
    {
        return $this->tenant?->id;
    }
}
