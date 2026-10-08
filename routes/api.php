<?php

use App\Http\Controllers\Api\V1\AdminAccountController;
use App\Http\Controllers\Api\V1\AdminAccountingController;
use App\Http\Controllers\Api\V1\AdminAuditEventController;
use App\Http\Controllers\Api\V1\AdminDepositController;
use App\Http\Controllers\Api\V1\AdminNetworkConnectionController;
use App\Http\Controllers\Api\V1\AdminNetworkController;
use App\Http\Controllers\Api\V1\AdminNetworkProductController;
use App\Http\Controllers\Api\V1\AdminNetworkReadinessController;
use App\Http\Controllers\Api\V1\AdminOperationsController;
use App\Http\Controllers\Api\V1\AdminPricingRuleController;
use App\Http\Controllers\Api\V1\AdminProviderSettlementController;
use App\Http\Controllers\Api\V1\AdminSaleController;
use App\Http\Controllers\Api\V1\AdminSellerCommissionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OwnerAccountingController;
use App\Http\Controllers\Api\V1\SellerCardDeliveryController;
use App\Http\Controllers\Api\V1\SellerCatalogController;
use App\Http\Controllers\Api\V1\SellerDepositController;
use App\Http\Controllers\Api\V1\SellerSaleController;
use App\Http\Controllers\Api\V1\SellerSoldCardController;
use App\Http\Controllers\Api\V1\SellerWalletController;
use Illuminate\Support\Facades\Route;

Route::get('/user', [AuthController::class, 'me'])->middleware(['auth:sanctum', 'account.active', 'abilities:account', 'throttle:api']);

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::middleware(['auth:sanctum', 'account.active', 'abilities:account', 'throttle:api'])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::prefix('admin')->middleware(['role:admin', 'abilities:admin'])->group(function (): void {
            Route::get('operations', [AdminOperationsController::class, 'show'])->name('admin.operations.show');
            Route::get('operations/failed-jobs', [AdminOperationsController::class, 'failedJobs'])->name('admin.operations.failed-jobs');
            Route::get('audit-events', [AdminAuditEventController::class, 'index'])->name('admin.audit-events.index');
            Route::apiResource('sales', AdminSaleController::class)->only(['index', 'show'])->names('admin.sales');
            Route::get('sales/{sale}/reviews', [AdminSaleController::class, 'reviews'])->name('admin.sales.reviews.index');
            Route::post('sales/{sale}/reviews', [AdminSaleController::class, 'requestReview'])->middleware('throttle:manual-review')->name('admin.sales.reviews.store');
            Route::apiResource('owners.settlements', AdminProviderSettlementController::class)->only(['index', 'show', 'store'])->scoped()->names('admin.settlements');
            Route::get('owners/{owner}/balance', [AdminProviderSettlementController::class, 'balance'])->name('admin.owner.balance');
            Route::apiResource('deposits', AdminDepositController::class)->only(['index', 'show'])->names('admin.deposits');
            Route::post('deposits/{deposit}/review', [AdminDepositController::class, 'review'])->name('admin.deposits.review');
            Route::get('accounting/summary', [AdminAccountingController::class, 'summary'])->name('admin.accounting.summary');
            Route::get('accounting/entries', [AdminAccountingController::class, 'index'])->name('admin.accounting.index');
            Route::get('accounting/entries/{entry}', [AdminAccountingController::class, 'show'])->name('admin.accounting.show');
            Route::get('accounts', [AdminAccountController::class, 'index'])->name('admin.accounts.index');
            Route::post('accounts', [AdminAccountController::class, 'store'])->name('admin.accounts.store');
            Route::patch('accounts/{user}/status', [AdminAccountController::class, 'updateStatus'])->name('admin.accounts.status');
            Route::apiResource('networks', AdminNetworkController::class)->only(['index', 'show', 'store'])->names('admin.networks');
            Route::patch('networks/{network}/status', [AdminNetworkController::class, 'updateStatus'])->name('admin.networks.status');
            Route::patch('networks/{network}/sales', [AdminNetworkReadinessController::class, 'updateSales'])->name('admin.networks.sales');
            Route::post('networks/{network}/connections/{connection}/health', [AdminNetworkReadinessController::class, 'check'])->scopeBindings()->middleware('throttle:network-health')->name('admin.connections.health');
            Route::apiResource('networks.connections', AdminNetworkConnectionController::class)->only(['index', 'store'])->names('admin.connections');
            Route::patch('networks/{network}/connections/{connection}/status', [AdminNetworkConnectionController::class, 'updateStatus'])->scopeBindings()->name('admin.connections.status');
            Route::apiResource('networks.products', AdminNetworkProductController::class)->only(['index', 'store'])->names('admin.products');
            Route::patch('networks/{network}/products/{product}/status', [AdminNetworkProductController::class, 'updateStatus'])->scopeBindings()->name('admin.products.status');
            Route::get('networks/{network}/products/{product}/pricing', [AdminPricingRuleController::class, 'index'])->scopeBindings()->name('admin.pricing.index');
            Route::post('networks/{network}/products/{product}/pricing', [AdminPricingRuleController::class, 'store'])->scopeBindings()->name('admin.pricing.store');
            Route::post('networks/{network}/products/{product}/pricing/{pricingRule}/commissions', [AdminSellerCommissionController::class, 'store'])->scopeBindings()->name('admin.commissions.store');
            Route::delete('networks/{network}/products/{product}/pricing/{pricingRule}/commissions/{commission}', [AdminSellerCommissionController::class, 'destroy'])->scopeBindings()->name('admin.commissions.destroy');
        });
        Route::prefix('seller')->middleware(['role:seller', 'abilities:seller'])->group(function (): void {
            Route::get('networks', [SellerCatalogController::class, 'networks'])->name('seller.catalog.networks');
            Route::get('networks/{network}/products', [SellerCatalogController::class, 'products'])->name('seller.catalog.products');
            Route::get('networks/{network}/products/{product}', [SellerCatalogController::class, 'product'])->name('seller.catalog.product');
            Route::apiResource('deposits', SellerDepositController::class)->only(['index', 'show', 'store'])->names('seller.deposits');
            Route::get('wallets', [SellerWalletController::class, 'index'])->name('seller.wallets.index');
            Route::get('wallets/{wallet}', [SellerWalletController::class, 'show'])->name('seller.wallets.show');
            Route::apiResource('sales', SellerSaleController::class)->only(['index', 'show', 'store'])->names('seller.sales');
            Route::post('sales/{sale}/card/reveal', [SellerSoldCardController::class, 'reveal'])->middleware('throttle:card-reveal')->name('seller.card.reveal');
            Route::post('sales/{sale}/delivery', [SellerCardDeliveryController::class, 'store'])->middleware('throttle:card-delivery')->name('seller.delivery.store');
            Route::get('sales/{sale}/delivery', [SellerCardDeliveryController::class, 'show'])->name('seller.delivery.show');
        });
        Route::prefix('owner')->middleware(['role:network_owner', 'abilities:network_owner'])->group(function (): void {
            Route::get('accounting/balance', [OwnerAccountingController::class, 'balance'])->name('owner.accounting.balance');
            Route::get('settlements', [OwnerAccountingController::class, 'settlements'])->name('owner.settlements.index');
            Route::get('settlements/{settlement}', [OwnerAccountingController::class, 'settlement'])->name('owner.settlements.show');
            Route::get('accounting/summary', [OwnerAccountingController::class, 'summary'])->name('owner.accounting.summary');
            Route::get('accounting/entries', [OwnerAccountingController::class, 'index'])->name('owner.accounting.index');
            Route::get('accounting/entries/{entry}', [OwnerAccountingController::class, 'show'])->name('owner.accounting.show');
        });
    });
});
