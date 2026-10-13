<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenant = App\Models\Tenant::query()->first();
app(App\Tenancy\TenancyManager::class)->initialize($tenant);
echo 'items '.App\Models\Item::query()->count().PHP_EOL;
echo 'users '.App\Models\User::query()->pluck('email')->implode(', ').PHP_EOL;
echo 'recipes '.App\Models\Recipe::query()->count().PHP_EOL;
echo 'balances '.App\Models\StockBalance::query()->count().PHP_EOL;
echo 'movements '.App\Models\StockMovement::query()->count().PHP_EOL;
