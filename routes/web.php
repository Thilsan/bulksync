<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BulkUploadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShopifyAuthController;
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
    Route::get('/upload/new',       [BulkUploadController::class, 'create'])->name('upload.create');
    Route::post('/upload',          [BulkUploadController::class, 'store'])->name('upload.store');
    Route::get('/upload/{session}', [BulkUploadController::class, 'show'])->name('upload.show');

    // Status polling endpoint — no CSRF needed (GET)
    Route::get('/upload/{session}/status', [BulkUploadController::class, 'status'])->name('upload.status');

    // Warm Shopify SKU cache on demand
    Route::post('/upload/warm-cache', [BulkUploadController::class, 'warmCache'])->name('upload.warm-cache');

    // Settings
    Route::get('/settings',                [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings',                [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/settings/test-shopify',   [SettingsController::class, 'testShopify'])->name('settings.test-shopify');
    Route::get('/settings/test-onedrive',  [SettingsController::class, 'testOnedrive'])->name('settings.test-onedrive');

    // Shopify OAuth
    Route::get('/auth/shopify/redirect',  [ShopifyAuthController::class, 'redirect'])->name('shopify.auth.redirect');
    Route::get('/auth/shopify/callback',  [ShopifyAuthController::class, 'callback'])->name('shopify.auth.callback');
});
