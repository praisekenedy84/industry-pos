<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\ProductionLineController;
use App\Http\Controllers\ProductionReceiptController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);

Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/two-factor', [AuthController::class, 'twoFactorForm'])->name('two-factor');
Route::post('/two-factor', [AuthController::class, 'twoFactor']);
Route::get('/two-factor/setup', [AuthController::class, 'twoFactorSetupForm'])->name('two-factor.setup');
Route::post('/two-factor/setup', [AuthController::class, 'twoFactorSetup']);
Route::post('/two-factor/continue', [AuthController::class, 'twoFactorContinue'])->name('two-factor.continue');
Route::get('/forgot-password', [AuthController::class, 'forgotForm'])->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'forgot'])->name('password.email');
Route::get('/reset-password/{token}', [AuthController::class, 'resetForm'])->name('password.reset');
Route::post('/reset-password/{token}', [AuthController::class, 'reset']);
Route::get('/workspaces', [AuthController::class, 'picker'])->name('workspaces');
Route::post('/workspaces', [AuthController::class, 'pick']);
Route::get('/invite/{token}', [AuthController::class, 'inviteForm'])->name('invite');
Route::post('/invite/{token}', [AuthController::class, 'invite']);

Route::middleware('central')->group(function () {
    Route::get('/dashboard', function () {
        if (Auth::guard('platform')->check()) {
            return redirect('/platform');
        }

        return redirect('/');
    });
    Route::get('/signup', [PlatformController::class, 'signupForm'])->name('signup');
    Route::post('/signup', [PlatformController::class, 'signup']);

    foreach (['platform', 'admin'] as $console) {
        Route::get('/'.$console.'/login', [PlatformController::class, 'loginForm'])->name($console.'.login');
        Route::post('/'.$console.'/login', [PlatformController::class, 'login'])->name($console.'.login.store');
        Route::post('/'.$console.'/logout', [PlatformController::class, 'logout'])->name($console.'.logout');

        Route::middleware('auth:platform')->prefix($console)->name($console.'.')->group(function () {
            Route::get('/', [PlatformController::class, 'tenants'])->name('tenants');
            Route::get('/tenants/{tenant}', [PlatformController::class, 'show'])->name('tenants.show');
            Route::put('/tenants/{tenant}', [PlatformController::class, 'update']);
            Route::post('/tenants/{tenant}/suspend', [PlatformController::class, 'suspend']);
            Route::post('/tenants/{tenant}/activate', [PlatformController::class, 'activate']);
            Route::post('/tenants/{tenant}/users', [PlatformController::class, 'storeUser']);
            Route::put('/tenants/{tenant}/features', [PlatformController::class, 'features']);
            Route::put('/tenants/{tenant}/limits', [PlatformController::class, 'limits']);
            Route::post('/tenants/{tenant}/impersonate', [PlatformController::class, 'impersonate']);
            Route::post('/tenants/{tenant}/export', [PlatformController::class, 'export']);
            Route::post('/tenants/{tenant}/delete', [PlatformController::class, 'scheduleDeletion']);
            Route::get('/features', [PlatformController::class, 'matrix'])->name('features');
            Route::get('/templates', [PlatformController::class, 'templates'])->name('templates');
            Route::put('/templates/{template}', [PlatformController::class, 'updateTemplate'])->where('template', '[a-z0-9_]+');
        });
    }
});

Route::prefix('w/{workspace}')
    ->where(['workspace' => '[a-z0-9-]{3,32}'])
    ->group(function () {
        Route::get('/', [AuthController::class, 'home'])->name('workspace.home');
        Route::get('/login', [AuthController::class, 'workspaceLoginForm'])->name('workspace.login');
        Route::post('/login', [AuthController::class, 'workspaceLogin']);
        Route::get('/two-factor', [AuthController::class, 'twoFactorForm'])->name('workspace.two-factor');
        Route::post('/two-factor', [AuthController::class, 'twoFactor']);
        Route::get('/two-factor/setup', [AuthController::class, 'twoFactorSetupForm'])->name('workspace.two-factor-setup');
        Route::post('/two-factor/setup', [AuthController::class, 'twoFactorSetup']);
        Route::post('/two-factor/continue', [AuthController::class, 'twoFactorContinue'])->name('workspace.two-factor-continue');
        Route::get('/unlock', [AuthController::class, 'unlockForm'])->name('workspace.unlock');
        Route::post('/unlock', [AuthController::class, 'unlock'])->name('workspace.unlock.store');
        Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('workspace.manifest');
        Route::get('/offline', [PwaController::class, 'offline'])->name('workspace.offline');
        Route::get('/icon/{size}', [PwaController::class, 'icon'])->whereNumber('size');

        Route::middleware('workspace.auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('/pin', [AuthController::class, 'pin'])->name('pin');
            Route::post('/locale', [AuthController::class, 'locale'])->name('locale');
            Route::post('/theme', [AuthController::class, 'theme'])->name('theme');
            Route::get('/password', [AuthController::class, 'passwordForm'])->name('workspace.password');
            Route::post('/password', [AuthController::class, 'password'])->name('workspace.password.update');
            Route::get('/account/sessions', [AuthController::class, 'sessions'])->name('account.sessions');
            Route::delete('/account/sessions/{session}', [AuthController::class, 'revokeSession'])->name('account.sessions.revoke');
            Route::get('/terminal/setup', [PwaController::class, 'terminalSetup'])->name('terminal.setup');
            Route::post('/terminal/setup', [PwaController::class, 'terminalStore']);
            Route::get('/dashboard', DashboardController::class)->name('dashboard');
            Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
            Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
            Route::post('/expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');
            Route::post('/branch', [AuthController::class, 'branch'])->name('branch');
            Route::post('/reauth', [AuthController::class, 'reauth'])->name('reauth');
            Route::get('/search', [DashboardController::class, 'search'])->name('search');
            Route::match(['get', 'post'], '/impersonation/leave', [AuthController::class, 'leaveImpersonation'])->name('impersonation.leave');
            Route::get('/onboarding', [DashboardController::class, 'onboarding'])->name('onboarding');
            Route::post('/onboarding', [DashboardController::class, 'completeOnboarding']);

            Route::get('/catalog/items', [CatalogController::class, 'items'])->name('catalog.items');
            Route::post('/catalog/items', [CatalogController::class, 'storeItem']);
            Route::post('/catalog/recipe-estimate', [CatalogController::class, 'estimateMixture'])->middleware('feature:module.production')->name('catalog.recipe-estimate');
            Route::get('/catalog/items/{item}/edit', [CatalogController::class, 'editItem'])->name('catalog.items.edit');
            Route::put('/catalog/items/{item}', [CatalogController::class, 'updateItem']);
            Route::post('/catalog/items/{item}/stock', [CatalogController::class, 'addStock'])->name('catalog.items.stock');
            Route::put('/catalog/items/{item}/recipe', [ProductionController::class, 'saveProductRecipe'])->middleware('feature:module.production')->name('catalog.items.recipe');
            Route::delete('/catalog/items/{item}', [CatalogController::class, 'destroyItem']);
            Route::post('/catalog/items/{item}/restore', [CatalogController::class, 'restoreItem'])->withTrashed()->name('catalog.items.restore');
            Route::get('/catalog/categories', [CatalogController::class, 'categories'])->name('catalog.categories');
            Route::post('/catalog/categories', [CatalogController::class, 'storeCategory']);
            Route::put('/catalog/categories/{category}', [CatalogController::class, 'updateCategory']);
            Route::delete('/catalog/categories/{category}', [CatalogController::class, 'destroyCategory']);
            Route::post('/catalog/categories/{category}/restore', [CatalogController::class, 'restoreCategory'])->withTrashed();
            Route::get('/catalog/units', [CatalogController::class, 'units'])->name('catalog.units');
            Route::post('/catalog/units', [CatalogController::class, 'storeUnit']);
            Route::post('/catalog/units/conversions', [CatalogController::class, 'storeConversion']);
            Route::put('/catalog/units/{unit}', [CatalogController::class, 'updateUnit']);
            Route::delete('/catalog/units/{unit}', [CatalogController::class, 'destroyUnit']);
            Route::get('/catalog/price-lists', [CatalogController::class, 'priceLists'])->name('catalog.prices');
            Route::post('/catalog/price-lists', [CatalogController::class, 'storePrice']);
            Route::put('/catalog/price-lists/{priceList}', [CatalogController::class, 'updatePriceList']);
            Route::delete('/catalog/price-lists/{priceList}', [CatalogController::class, 'destroyPriceList']);
            Route::put('/catalog/prices/{price}', [CatalogController::class, 'updatePrice']);
            Route::delete('/catalog/prices/{price}', [CatalogController::class, 'destroyPrice']);
            Route::post('/catalog/import', [CatalogController::class, 'import']);

            Route::get('/inventory', [InventoryController::class, 'stock'])->name('inventory');
            Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
            Route::get('/inventory/transfers', [InventoryController::class, 'transfers'])->name('inventory.transfers');
            Route::post('/inventory/transfers', [InventoryController::class, 'storeTransfer']);
            Route::post('/inventory/transfers/{transfer}/send', [InventoryController::class, 'sendTransfer']);
            Route::post('/inventory/transfers/{transfer}/receive', [InventoryController::class, 'receiveTransfer']);
            Route::get('/inventory/counts', [InventoryController::class, 'counts'])->name('inventory.counts');
            Route::post('/inventory/counts', [InventoryController::class, 'storeCount']);
            Route::post('/inventory/counts/{count}/post', [InventoryController::class, 'postCount']);
            Route::get('/inventory/lots', [InventoryController::class, 'lots'])->name('inventory.lots');
            Route::get('/inventory/direct-receipts', [ProductionReceiptController::class, 'index'])->name('inventory.direct-receipts');
            Route::post('/inventory/direct-receipts', [ProductionReceiptController::class, 'store']);
            Route::post('/inventory/adjustments', [InventoryController::class, 'adjust']);

            Route::middleware('feature:module.production')->group(function () {
                Route::get('/production/batches', [ProductionController::class, 'orders'])->name('production.batches');
                Route::post('/production/batches', [ProductionController::class, 'storeBatch']);
                Route::get('/production/batches/{order}', [ProductionController::class, 'showOrder'])->name('production.batches.show');
                Route::post('/production/batches/{order}/complete', [ProductionController::class, 'completeBatch']);
                Route::post('/production/batches/{order}/cancel', [ProductionController::class, 'cancel']);
                Route::post('/production/batches/{order}/reverse', [ProductionController::class, 'reverseBatch']);
                Route::get('/production/lines', [ProductionLineController::class, 'index'])->name('production.lines');
                Route::post('/production/lines', [ProductionLineController::class, 'store']);
                Route::put('/production/lines/{line}', [ProductionLineController::class, 'update']);
                Route::post('/production/orders/{order}/hold', [ProductionController::class, 'hold']);
                Route::post('/production/orders/{order}/resume', [ProductionController::class, 'resume']);
                Route::post('/production/orders/{order}/release', [ProductionController::class, 'release']);
                Route::post('/production/orders/{order}/start', [ProductionController::class, 'start']);
                Route::get('/production/recipes', [ProductionController::class, 'recipes'])->name('production.recipes');
                Route::post('/production/recipes', [ProductionController::class, 'storeRecipe']);
                Route::get('/production/recipes/{recipe}', [ProductionController::class, 'showRecipe'])->name('production.recipes.show');
                Route::put('/production/recipes/{recipe}', [ProductionController::class, 'updateRecipe']);
                Route::get('/production/orders', [ProductionController::class, 'orders'])->name('production.orders');
                Route::post('/production/orders', [ProductionController::class, 'storeOrder']);
                Route::get('/production/orders/{order}', [ProductionController::class, 'showOrder'])->name('production.orders.show');
                Route::post('/production/orders/{order}/complete', [ProductionController::class, 'complete']);
                Route::post('/production/orders/{order}/cancel', [ProductionController::class, 'cancel']);
            });

            Route::middleware('feature:module.purchasing')->group(function () {
                Route::get('/purchasing/suppliers', [PurchasingController::class, 'suppliers'])->name('purchasing.suppliers');
                Route::post('/purchasing/suppliers', [PurchasingController::class, 'storeSupplier']);
                Route::put('/purchasing/suppliers/{supplier}', [PurchasingController::class, 'updateSupplier']);
                Route::delete('/purchasing/suppliers/{supplier}', [PurchasingController::class, 'destroySupplier']);
                Route::post('/purchasing/suppliers/{supplier}/restore', [PurchasingController::class, 'restoreSupplier'])->withTrashed();
                Route::get('/purchasing/orders', [PurchasingController::class, 'orders'])->name('purchasing.orders');
                Route::post('/purchasing/orders', [PurchasingController::class, 'storeOrder']);
                Route::get('/purchasing/orders/{order}', [PurchasingController::class, 'showOrder'])->name('purchasing.orders.show');
                Route::post('/purchasing/orders/{order}/approve', [PurchasingController::class, 'approve']);
                Route::post('/purchasing/orders/{order}/receive', [PurchasingController::class, 'receive']);
                Route::post('/purchasing/returns', [PurchasingController::class, 'supplierReturn']);
            });

            Route::middleware('feature:module.pos')->group(function () {
                Route::get('/pos', [PosController::class, 'terminal'])->name('pos');
                Route::post('/pos/cart', [PosController::class, 'rememberCart']);
                Route::get('/pos/sales', [PosController::class, 'index'])->name('pos.sales.index');
                Route::post('/pos/sales', [PosController::class, 'sell']);
                Route::put('/pos/sales/{sale}', [PosController::class, 'update']);
                Route::delete('/pos/sales/{sale}', [PosController::class, 'destroy']);
                Route::post('/pos/sync', [PosController::class, 'sync']);
                Route::post('/pos/drafts', [PosController::class, 'hold']);
                Route::delete('/pos/drafts/{draft}', [PosController::class, 'discardDraft']);
                Route::get('/pos/lookup', [PosController::class, 'lookup']);
                Route::post('/pos/returns', [PosController::class, 'returnSale']);
                Route::get('/pos/sales/{sale}', [PosController::class, 'show'])->name('pos.sales.show');
                Route::get('/pos/sales/{sale}/receipt', [PosController::class, 'receipt'])->name('pos.receipt');
                Route::get('/pos/sales/{sale}/receipt.pdf', [PosController::class, 'receiptPdf'])->name('pos.receipt.pdf');
                Route::post('/pos/sales/{sale}/reprint', [PosController::class, 'reprint']);
            });

            Route::middleware('feature:module.customers')->group(function () {
                Route::get('/customers', [CustomerController::class, 'index'])->name('customers');
                Route::post('/customers', [CustomerController::class, 'store']);
                Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
                Route::put('/customers/{customer}', [CustomerController::class, 'update']);
                Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
                Route::post('/customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed();
                Route::post('/customers/{customer}/payments', [CustomerController::class, 'payment']);
            });

            Route::get('/reports', [ReportController::class, 'index'])->name('reports');
            Route::get('/reports/{report}/export', [ReportController::class, 'export'])->where('report', '[A-Za-z0-9\.\-_]+');
            Route::post('/reports/{report}/subscribe', [ReportController::class, 'subscribe'])->where('report', '[A-Za-z0-9\.\-_]+');
            Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show')->where('report', '[A-Za-z0-9\.\-_]+');

            Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
            Route::put('/settings', [SettingsController::class, 'update']);
            Route::get('/settings/branches', [SettingsController::class, 'branches'])->name('settings.branches');
            Route::post('/settings/branches', [SettingsController::class, 'storeBranch']);
            Route::put('/settings/branches/{branch}', [SettingsController::class, 'updateBranch']);
            Route::post('/settings/branches/{branch}/suspend', [SettingsController::class, 'suspendBranch']);
            Route::get('/settings/users', [SettingsController::class, 'users'])->name('settings.users');
            Route::post('/settings/users', [SettingsController::class, 'invite']);
            Route::post('/settings/users/import', [SettingsController::class, 'importUsers']);
            Route::get('/settings/users/{user}', [SettingsController::class, 'showUser'])->name('settings.users.show');
            Route::put('/settings/users/{user}', [SettingsController::class, 'updateUser']);
            Route::post('/settings/users/{user}/suspend', [SettingsController::class, 'suspendUser']);
            Route::post('/settings/users/{user}/deactivate', [SettingsController::class, 'deactivateUser']);
            Route::post('/settings/users/{user}/sessions', [SettingsController::class, 'revokeSessions']);
            Route::get('/settings/roles', [SettingsController::class, 'roles'])->name('settings.roles');
            Route::post('/settings/roles', [SettingsController::class, 'storeRole']);
            Route::get('/settings/roles/{role}', [SettingsController::class, 'editRole'])->name('settings.roles.edit');
            Route::put('/settings/roles/{role}', [SettingsController::class, 'updateRole']);
            Route::post('/settings/roles/{role}/clone', [SettingsController::class, 'cloneRole']);
            Route::get('/settings/audit', [SettingsController::class, 'audit'])->name('settings.audit');
            Route::get('/settings/system-users', [SettingsController::class, 'systemUsers'])->name('settings.system');
            Route::post('/settings/system-users', [SettingsController::class, 'storeSystemUser']);
            Route::post('/settings/system-users/{systemUser}/tokens', [SettingsController::class, 'createToken']);
            Route::post('/settings/tokens/{token}/revoke', [SettingsController::class, 'revokeToken']);
            Route::post('/settings/tokens/{token}/rotate', [SettingsController::class, 'rotateToken']);
            Route::get('/settings/approvals', [SettingsController::class, 'approvals'])->name('settings.approvals');
            Route::post('/settings/approvals/{approval}', [SettingsController::class, 'resolveApproval']);
        });
    });
