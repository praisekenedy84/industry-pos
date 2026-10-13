<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait UsesTenantConnection
{
    public function initializeUsesTenantConnection(): void
    {
        $this->connection = 'tenant';
        $this->keyType = 'string';
        $this->incrementing = false;
    }

    protected static function bootUsesTenantConnection(): void
    {
        static::creating(function ($model): void {
            if (! $model->getKey()) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}
