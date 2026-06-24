<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BulkUploadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\OneDriveAuthController;
use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\ImageAuditController;
use App\Http\Controllers\SkuCheckerController;
use App\Http\Controllers\StoreImageSyncController;
use App\Http\Controllers\MetafieldUpdateController;
use Illuminate\Support\Facades\Route;

// Auth
Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Protected admin routes
Route::middleware('auth')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Bulk upload
    Route::get('/upload/history',   [BulkUploadController::class, 'history'])->name('upload.history');

    Route::post('/image-audit/start',                          [ImageAuditController::class, 'start'])->name('image-audit.start');
    Route::get('/image-audit',                                 [ImageAuditController::class, 'index'])->name('image-audit.index');
    Route::get('/image-audit/{imageAuditSession}',             [ImageAuditController::class, 'show'])->name('image-audit.show');
    Route::get('/image-audit/{imageAuditSession}/status',      [ImageAuditController::class, 'status'])->name('image-audit.status');
    Route::get('/image-audit/{imageAuditSession}/items',       [ImageAuditController::class, 'items'])->name('image-audit.items');
    Route::get('/image-audit/{imageAuditSession}/download',    [ImageAuditController::class, 'download'])->name('image-audit.download');
    Route::delete('/image-audit/{imageAuditSession}',          [ImageAuditController::class, 'destroy'])->name('image-audit.destroy');

    Route::get('/store-image-sync',                          [StoreImageSyncController::class, 'index'])->name('store-image-sync.index');
    Route::post('/store-image-sync',                         [StoreImageSyncController::class, 'start'])->name('store-image-sync.start');
    Route::get('/store-image-sync/{token}/status',           [StoreImageSyncController::class, 'status'])->name('store-image-sync.status');
    Route::get('/store-image-sync/{token}/download',         [StoreImageSyncController::class, 'download'])->name('store-image-sync.download');
    Route::get('/store-image-sync/{token}',                  [StoreImageSyncController::class, 'show'])->name('store-image-sync.show');

    Route::get('/sku-checker',                              [SkuCheckerController::class, 'index'])->name('sku-checker.index');
    Route::post('/sku-checker',                             [SkuCheckerController::class, 'check'])->name('sku-checker.check');
    Route::post('/sku-checker/csv-compare',                 [SkuCheckerController::class, 'csvCompare'])->name('sku-checker.csv-compare');
    Route::get('/sku-checker/history',                      [SkuCheckerController::class, 'history'])->name('sku-checker.history');
    Route::get('/sku-checker/{skuCheckSession}',            [SkuCheckerController::class, 'show'])->name('sku-checker.show');
    Route::get('/sku-checker/{skuCheckSession}/status',     [SkuCheckerController::class, 'status'])->name('sku-checker.status');
    Route::get('/sku-checker/{skuCheckSession}/download',   [SkuCheckerController::class, 'download'])->name('sku-checker.download');
    Route::delete('/sku-checker/{skuCheckSession}',         [SkuCheckerController::class, 'destroy'])->name('sku-checker.destroy');
    Route::get('/upload/new',       [BulkUploadController::class, 'create'])->name('upload.create');
    Route::post('/upload',          [BulkUploadController::class, 'store'])->name('upload.store');
    Route::get('/upload/{session}',                       [BulkUploadController::class, 'show'])->name('upload.show');
    Route::delete('/upload/{session}',                    [BulkUploadController::class, 'destroy'])->name('upload.destroy');
    Route::post('/upload/{session}/sync-variant-images',  [BulkUploadController::class, 'syncVariantImages'])->name('upload.sync-variant-images');

    // Status polling endpoint — no CSRF needed (GET)
    Route::get('/upload/{session}/status', [BulkUploadController::class, 'status'])->name('upload.status');

    // Warm Shopify SKU cache on demand
    Route::post('/upload/warm-cache', [BulkUploadController::class, 'warmCache'])->name('upload.warm-cache');

    // Settings
    Route::get('/settings',                [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings',                [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/settings/test-onedrive',  [SettingsController::class, 'testOnedrive'])->name('settings.test-onedrive');
    Route::post('/settings/clear-cache',   [SettingsController::class, 'clearCache'])->name('settings.clear-cache');

    // Stores
    Route::get('/stores',                     [StoreController::class, 'index'])->name('stores.index');
    Route::post('/stores',                    [StoreController::class, 'store'])->name('stores.store');
    Route::put('/stores/{store}',             [StoreController::class, 'update'])->name('stores.update');
    Route::delete('/stores/{store}',          [StoreController::class, 'destroy'])->name('stores.destroy');
    Route::post('/stores/{store}/switch',     [StoreController::class, 'switch'])->name('stores.switch');
    Route::get('/stores/{store}/test',        [StoreController::class, 'test'])->name('stores.test');

    // Super admin
    Route::middleware('super-admin')->prefix('super-admin')->name('super-admin.')->group(function () {
        Route::get('/',                              [SuperAdminController::class, 'index'])->name('index');
        Route::post('/users',                        [SuperAdminController::class, 'storeUser'])->name('users.store');
        Route::post('/users/{user}/toggle',          [SuperAdminController::class, 'toggleUser'])->name('users.toggle');
        Route::post('/users/{user}/toggle-admin',    [SuperAdminController::class, 'toggleSuperAdmin'])->name('users.toggle-admin');
        Route::post('/users/{user}/permissions',     [SuperAdminController::class, 'updatePermissions'])->name('users.permissions');
        Route::post('/users/{user}/stores',          [SuperAdminController::class, 'updateStores'])->name('users.stores');
    });

    // Metafield Update
    Route::get('/metafield-update',         [MetafieldUpdateController::class, 'index'])->name('metafield-update.index');
    Route::post('/metafield-update/upload', [MetafieldUpdateController::class, 'upload'])->name('metafield-update.upload');
    Route::get('/metafield-update/status',  [MetafieldUpdateController::class, 'status'])->name('metafield-update.status');
    Route::get('/metafield-update/poll',    [MetafieldUpdateController::class, 'poll'])->name('metafield-update.poll');

    // Shopify OAuth
    Route::get('/auth/shopify/redirect',   [ShopifyAuthController::class,  'redirect'])->name('shopify.auth.redirect');
    Route::get('/auth/shopify/callback',   [ShopifyAuthController::class,  'callback'])->name('shopify.auth.callback');

    // OneDrive OAuth
    Route::get('/auth/onedrive/redirect',  [OneDriveAuthController::class, 'redirect'])->name('onedrive.auth.redirect');
    Route::get('/auth/onedrive/callback',  [OneDriveAuthController::class, 'callback'])->name('onedrive.auth.callback');
});
