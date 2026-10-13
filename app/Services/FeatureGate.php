<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Setting;
use App\Models\TenantFeature;
use App\Models\TenantLimit;
use App\Tenancy\TenancyManager;

class FeatureGate
{
    public function __construct(private TenancyManager $tenancy) {}

    public function active(string $key, ?string $branchId = null): bool
    {
        $tenant = $this->tenancy->tenant();
        if (! $tenant) {
            return false;
        }

        $feature = Feature::query()->where('key', $key)->first();
        if ($feature && ! $feature->globally_enabled) {
            return false;
        }

        $override = TenantFeature::query()
            ->where('tenant_id', $tenant->id)
            ->where('feature_key', $key)
            ->first();

        $enabled = $feature ? (bool) $feature->default_value : true;
        if ($override) {
            if ($override->expires_at && $override->expires_at->isPast()) {
                $enabled = $feature ? (bool) $feature->default_value : false;
            } else {
                $enabled = (bool) $override->value;
            }
        }

        if (! $enabled || ! $branchId) {
            return $enabled;
        }

        $branchFlags = Setting::getValue('branch_modules', []);
        if (is_array($branchFlags) && array_key_exists($branchId, $branchFlags) && array_key_exists($key, $branchFlags[$branchId])) {
            return (bool) $branchFlags[$branchId][$key];
        }

        return true;
    }

    public function limit(string $key): ?int
    {
        $tenant = $this->tenancy->tenant();
        if (! $tenant) {
            return null;
        }

        $row = TenantLimit::query()->where('tenant_id', $tenant->id)->where('limit_key', $key)->first();

        return $row ? (int) $row->value : null;
    }

    public function map(): array
    {
        $out = [];
        foreach (\App\Support\FeatureCatalog::all() as $feature) {
            $out[$feature['key']] = $this->active($feature['key']);
        }

        return $out;
    }
}
