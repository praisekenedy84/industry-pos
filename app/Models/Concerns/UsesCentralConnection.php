<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait UsesCentralConnection
{
    public function initializeUsesCentralConnection(): void
    {
        $this->connection = 'central';
        $this->keyType = 'string';
        $this->incrementing = false;
    }

    protected static function bootUsesCentralConnection(): void
    {
        static::creating(function ($model): void {
            if (! $model->getKey()) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}
