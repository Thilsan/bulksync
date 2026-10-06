<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BarcodeImageController;
use App\Http\Controllers\BulkUploadController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrdersDashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\OneDriveAuthController;
use App\Http\Controllers\ProductRequestSheetAuthController;
use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\ImageAuditController;
use App\Http\Controllers\CollectionContentController;
use App\Http\Controllers\SeoAuditController;
use App\Http\Controllers\SeoImpactController;
use App\Http\Controllers\StrikingDistanceController;
use App\Http\Controllers\SkuCheckerController;
use App\Http\Controllers\StoreImageSyncController;
use App\Http\Controllers\MetafieldUpdateController;
use App\Http\Controllers\AiContentController;
use App\Http\Controllers\PhotoEditorController;
use App\Http\Controllers\PhotoshootRoomController;
use App\Http\Controllers\ProductPerformanceController;
use App\Http\Controllers\ProductRequestController;
use App\Http\Controllers\QueueController;
use Illuminate\Support\Facades\Route;

// Auth
Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Protected admin routes
Route::middleware('auth')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Orders across every storefront. Read-only, and the only screen in the
    // app whose numbers come from the ecommerce server rather than this one.
    Route::get('/management-dashboard', [OrdersDashboardController::class, 'index'])->name('orders.dashboard');

    // The endpoint has no single number for "this platform's manual orders" —
    // platform and order-type are two separate breakdowns it never crosses —
    // so the platform table fetches one platform's own split on demand rather
    // than paying for every platform's on every page load.
    Route::get('/management-dashboard/platform/{platform}/order-types', [OrdersDashboardController::class, 'platformOrderTypes'])->name('orders.dashboard.platform-order-types');

    // Who owns what in the e-commerce department. A static org chart rather
    // than a module: the table is written into the view, so there is nothing
    // to query and nothing to keep in sync but the sheet it mirrors.
    Route::view('/team', 'team.index')->name('team.index');

    // Best, low and non-selling products per website. Its own page rather than
    // a tab on the management dashboard: it reads the nightly product-sales
    // tables, not the orders endpoint, and answers a buyer's question rather
    // than a revenue one.
    Route::get('/product-performance',          [ProductPerformanceController::class, 'index'])->name('product-performance.index');
    Route::get('/product-performance/download', [ProductPerformanceController::class, 'download'])->name('product-performance.download');
    Route::get('/product-performance/divisions',          [ProductPerformanceController::class, 'divisions'])->name('product-performance.divisions');
    Route::get('/product-performance/divisions/download', [ProductPerformanceController::class, 'divisionsDownload'])->name('product-performance.divisions.download');
    Route::post('/product-performance/refresh', [ProductPerformanceController::class, 'refresh'])->middleware('super-admin')->name('product-performance.refresh');

    // Bulk upload
    Route::get('/upload',           [BulkUploadController::class, 'dashboard'])->name('upload.dashboard');
    Route::get('/upload/history',   [BulkUploadController::class, 'history'])->name('upload.history');

    Route::post('/image-audit/start',                          [ImageAuditController::class, 'start'])->name('image-audit.start');
    Route::get('/image-audit',                                 [ImageAuditController::class, 'index'])->name('image-audit.index');
    Route::get('/image-audit/{imageAuditSession}',             [ImageAuditController::class, 'show'])->name('image-audit.show');
    Route::get('/image-audit/{imageAuditSession}/status',      [ImageAuditController::class, 'status'])->name('image-audit.status');
    Route::get('/image-audit/{imageAuditSession}/items',       [ImageAuditController::class, 'items'])->name('image-audit.items');
    Route::get('/image-audit/{imageAuditSession}/download',    [ImageAuditController::class, 'download'])->name('image-audit.download');
    Route::delete('/image-audit/{imageAuditSession}',          [ImageAuditController::class, 'destroy'])->name('image-audit.destroy');

    // SEO Audit: reports what is missing or wrong about the catalogue's search
    // fields. Read-only — every fix is made in the AI Content Generator, so
    // nothing here needs a write scope. Ordered so /start is not swallowed by
    // the {seoAuditSession} route.
    Route::post('/seo-audit/start',                        [SeoAuditController::class, 'start'])->name('seo-audit.start');
    Route::get('/seo-audit',                               [SeoAuditController::class, 'index'])->name('seo-audit.index');

    // Declared above the {seoAuditSession} route below, which would otherwise
    // match "impact" as a session id and 404 on the binding.
    Route::get('/seo-audit/impact',                        [SeoImpactController::class, 'index'])->name('seo-audit.impact');
    Route::get('/seo-audit/almost-ranking',                [StrikingDistanceController::class, 'index'])->name('seo-audit.striking-distance');
    Route::get('/seo-audit/{seoAuditSession}',             [SeoAuditController::class, 'show'])->name('seo-audit.show');
    Route::get('/seo-audit/{seoAuditSession}/status',      [SeoAuditController::class, 'status'])->name('seo-audit.status');
    Route::get('/seo-audit/{seoAuditSession}/items',       [SeoAuditController::class, 'items'])->name('seo-audit.items');
    Route::get('/seo-audit/{seoAuditSession}/duplicates',  [SeoAuditController::class, 'duplicates'])->name('seo-audit.duplicates');
    Route::get('/seo-audit/{seoAuditSession}/merge-candidates', [SeoAuditController::class, 'mergeCandidates'])->name('seo-audit.merge-candidates');
    Route::get('/seo-audit/{seoAuditSession}/download',    [SeoAuditController::class, 'download'])->name('seo-audit.download');
    Route::post('/seo-audit/{seoAuditSession}/fix',        [SeoAuditController::class, 'fix'])->name('seo-audit.fix');
    Route::delete('/seo-audit/{seoAuditSession}',          [SeoAuditController::class, 'destroy'])->name('seo-audit.destroy');

    // Collection SEO: the pages that rank for a category rather than a model
    // name. Separate from the AI Content Generator because a collection has no
    // SKU to look it up by and no photograph to write from.
    Route::get('/collection-seo',                                   [CollectionContentController::class, 'index'])->name('collection-content.index');
    Route::post('/collection-seo',                                  [CollectionContentController::class, 'store'])->name('collection-content.store');
    Route::post('/collection-seo/from-audit/{seoAuditSession}',     [CollectionContentController::class, 'fromAudit'])->name('collection-content.from-audit');
    Route::get('/collection-seo/{collectionContentSession}',        [CollectionContentController::class, 'show'])->name('collection-content.show');
    Route::get('/collection-seo/{collectionContentSession}/status', [CollectionContentController::class, 'status'])->name('collection-content.status');
    Route::post('/collection-seo/{collectionContentSession}/push',  [CollectionContentController::class, 'push'])->name('collection-content.push');
    Route::delete('/collection-seo/{collectionContentSession}',     [CollectionContentController::class, 'destroy'])->name('collection-content.destroy');

    Route::get('/store-image-sync',                          [StoreImageSyncController::class, 'index'])->name('store-image-sync.index');
    Route::post('/store-image-sync',                         [StoreImageSyncController::class, 'start'])->name('store-image-sync.start');
    Route::get('/store-image-sync/{token}/status',           [StoreImageSyncController::class, 'status'])->name('store-image-sync.status');
    Route::get('/store-image-sync/{token}/download',         [StoreImageSyncController::class, 'download'])->name('store-image-sync.download');
    Route::get('/store-image-sync/{token}',                  [StoreImageSyncController::class, 'show'])->name('store-image-sync.show');

    // Barcode Image Grabber: internal barcodes + a public website, out comes a
    // ZIP with one folder per barcode. Ordered so /history and /start are
    // matched before the {barcodeImageSession} wildcard can swallow them.
    Route::get('/barcode-images',                                       [BarcodeImageController::class, 'index'])->name('barcode-images.index');
    Route::post('/barcode-images',                                      [BarcodeImageController::class, 'start'])->name('barcode-images.start');
    Route::get('/barcode-images/history',                               [BarcodeImageController::class, 'history'])->name('barcode-images.history');
    Route::get('/barcode-images/{barcodeImageSession}',                 [BarcodeImageController::class, 'show'])->name('barcode-images.show');
    Route::get('/barcode-images/{barcodeImageSession}/status',          [BarcodeImageController::class, 'status'])->name('barcode-images.status');
    Route::get('/barcode-images/{barcodeImageSession}/items',           [BarcodeImageController::class, 'items'])->name('barcode-images.items');
    Route::post('/barcode-images/{barcodeImageSession}/push',            [BarcodeImageController::class, 'push'])->name('barcode-images.push');
    Route::get('/barcode-images/{barcodeImageSession}/download',        [BarcodeImageController::class, 'download'])->name('barcode-images.download');
    Route::get('/barcode-images/{barcodeImageSession}/download/{barcode}', [BarcodeImageController::class, 'downloadOne'])->name('barcode-images.download-one');
    Route::delete('/barcode-images/{barcodeImageSession}',              [BarcodeImageController::class, 'destroy'])->name('barcode-images.destroy');

    Route::get('/sku-checker',                              [SkuCheckerController::class, 'index'])->name('sku-checker.index');
    Route::post('/sku-checker',                             [SkuCheckerController::class, 'check'])->name('sku-checker.check');
    Route::post('/sku-checker/csv-compare',                 [SkuCheckerController::class, 'csvCompare'])->name('sku-checker.csv-compare');
    Route::get('/sku-checker/history',                      [SkuCheckerController::class, 'history'])->name('sku-checker.history');
    Route::get('/sku-checker/{skuCheckSession}',            [SkuCheckerController::class, 'show'])->name('sku-checker.show');
    Route::get('/sku-checker/{skuCheckSession}/status',     [SkuCheckerController::class, 'status'])->name('sku-checker.status');
    Route::get('/sku-checker/{skuCheckSession}/results',    [SkuCheckerController::class, 'results'])->name('sku-checker.results');
    Route::post('/sku-checker/{skuCheckSession}/variant-export', [SkuCheckerController::class, 'buildVariantExport'])->name('sku-checker.variant-export');
    Route::get('/sku-checker/{skuCheckSession}/variant-export', [SkuCheckerController::class, 'downloadVariantExport'])->name('sku-checker.variant-export.download');
    Route::get('/sku-checker/{skuCheckSession}/variants',   [SkuCheckerController::class, 'variants'])->name('sku-checker.variants');
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

    /*
     * Photo Editor — OneDrive → Photoroom → review → Shopify.
     * "history" is declared before "{session}", or the word would be read as a
     * session id and 404 every time.
     */
    Route::get('/photo-editor',                  [PhotoEditorController::class, 'index'])->name('photo-editor.index');
    Route::post('/photo-editor',                 [PhotoEditorController::class, 'store'])->name('photo-editor.store');
    Route::get('/photo-editor/history',          [PhotoEditorController::class, 'history'])->name('photo-editor.history');
    Route::get('/photo-editor/{session}',        [PhotoEditorController::class, 'show'])->name('photo-editor.show');
    Route::get('/photo-editor/{session}/status', [PhotoEditorController::class, 'status'])->name('photo-editor.status');

    // Between finding the photos and spending anything on them: each SKU
    // folder gets its own settings before a single credit is used.
    Route::get('/photo-editor/{session}/configure', [PhotoEditorController::class, 'configure'])->name('photo-editor.configure');
    Route::post('/photo-editor/{session}/start',    [PhotoEditorController::class, 'start'])->name('photo-editor.start');
    Route::get('/photo-editor/{session}/item/{item}/onedrive-thumb', [PhotoEditorController::class, 'onedriveThumb'])
        ->name('photo-editor.onedrive-thumb');
    Route::post('/photo-editor/{session}/push',  [PhotoEditorController::class, 'push'])->name('photo-editor.push');

    // Which storefront the run's photos are destined for. Separate from the
    // header's store picker, which only decides what a new run starts against:
    // a run already holds its own, and the images were matched to SKUs in it.
    Route::post('/photo-editor/{session}/store', [PhotoEditorController::class, 'changeStore'])->name('photo-editor.change-store');
    Route::delete('/photo-editor/{session}',     [PhotoEditorController::class, 'destroy'])->name('photo-editor.destroy');

    Route::post('/photo-editor/{session}/item/{item}/reedit', [PhotoEditorController::class, 'reedit'])
        ->name('photo-editor.item.reedit');

    // Edited files live outside the web root, so every read comes back through here.
    Route::get('/photo-editor/{session}/item/{item}/{variant}', [PhotoEditorController::class, 'preview'])
        ->whereIn('variant', ['before', 'after', 'full'])
        ->name('photo-editor.preview');

    // A one-off diagnostic file, for looking at what Photoroom actually
    // returned when nobody has any other way to reach the server's disk.
    Route::get('/photo-editor/{session}/debug/{filename}', [PhotoEditorController::class, 'debugFile'])
        ->where('filename', '[A-Za-z0-9._-]+')
        ->name('photo-editor.debug-file');

    /*
     * Two selected pieces, laid out as one set image. A POST because the two
     * ids and their order are the request, and the answer is a file.
     */
    Route::post('/photo-editor/{session}/combo', [PhotoEditorController::class, 'combo'])
        ->name('photo-editor.combo');

    Route::post('/photo-editor/{session}/download', [PhotoEditorController::class, 'downloadSelected'])
        ->name('photo-editor.download-selected');

    // The same bytes the push sends, for the items this app cannot push itself.
    Route::get('/photo-editor/{session}/item/{item}/download', [PhotoEditorController::class, 'download'])
        ->name('photo-editor.download');

    // Settings
    Route::get('/settings',                [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings',                [SettingsController::class, 'update'])->name('settings.update');
    Route::put('/settings/sheet-app',      [SettingsController::class, 'updateSheetApp'])->name('settings.sheet-app');
    Route::get('/settings/test-onedrive',  [SettingsController::class, 'testOnedrive'])->name('settings.test-onedrive');
    Route::get('/settings/test-gemini',    [SettingsController::class, 'testGemini'])->name('settings.test-gemini');
    Route::post('/settings/clear-cache',   [SettingsController::class, 'clearCache'])->name('settings.clear-cache');
    Route::put('/settings/mail',           [SettingsController::class, 'updateMail'])->name('settings.mail.update');
    Route::post('/settings/mail/test',     [SettingsController::class, 'testMail'])->name('settings.mail.test');

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
        Route::get('/activity',                      [SuperAdminController::class, 'activity'])->name('activity');
        Route::get('/live-feed',                     [SuperAdminController::class, 'liveFeed'])->name('live-feed');
        Route::post('/users',                        [SuperAdminController::class, 'storeUser'])->name('users.store');
        Route::post('/users/{user}/toggle',          [SuperAdminController::class, 'toggleUser'])->name('users.toggle');
        Route::post('/users/{user}/toggle-admin',    [SuperAdminController::class, 'toggleSuperAdmin'])->name('users.toggle-admin');
        Route::post('/users/{user}/permissions',     [SuperAdminController::class, 'updatePermissions'])->name('users.permissions');
        Route::post('/users/{user}/stores',          [SuperAdminController::class, 'updateStores'])->name('users.stores');

        // Queues
        Route::get('/queues',                        [QueueController::class, 'index'])->name('queues.index');
        Route::get('/queues/status',                 [QueueController::class, 'status'])->name('queues.status');
        Route::get('/queues/history',                [QueueController::class, 'history'])->name('queues.history');
        Route::get('/queues/logs',                   [QueueController::class, 'logs'])->name('queues.logs');
        Route::get('/queues/failed/{uuid}',          [QueueController::class, 'failedDetail'])->name('queues.failed.detail');
        Route::post('/queues/restart',               [QueueController::class, 'restartWorkers'])->name('queues.restart');
        Route::get('/queues/failed',                 [QueueController::class, 'failedPage'])->name('queues.failed.page');
        Route::post('/queues/failed/retry',          [QueueController::class, 'retryFailed'])->name('queues.failed.retry');
        Route::post('/queues/failed/forget',         [QueueController::class, 'forgetFailed'])->name('queues.failed.forget');
        Route::post('/queues/purge',                 [QueueController::class, 'purge'])->name('queues.purge');
        Route::post('/queues/sessions/{type}/{id}/resume', [QueueController::class, 'resumeSession'])->name('queues.sessions.resume');
    });

    // Metafield Update
    Route::get('/metafield-update',         [MetafieldUpdateController::class, 'index'])->name('metafield-update.index');
    Route::post('/metafield-update/upload', [MetafieldUpdateController::class, 'upload'])->name('metafield-update.upload');
    Route::get('/metafield-update/status',  [MetafieldUpdateController::class, 'status'])->name('metafield-update.status');
    Route::get('/metafield-update/poll',    [MetafieldUpdateController::class, 'poll'])->name('metafield-update.poll');

    // AI Content
    Route::get('/ai-content',                          [AiContentController::class, 'index'])->name('ai-content.index');
    Route::post('/ai-content',                         [AiContentController::class, 'store'])->name('ai-content.store');
    // Both must stay above /{aiContentSession}, or the words get bound as an id.
    Route::get('/ai-content/dashboard',                [AiContentController::class, 'dashboard'])->name('ai-content.dashboard');
    Route::get('/ai-content/history',                  [AiContentController::class, 'history'])->name('ai-content.history');
    Route::get('/ai-content/{aiContentSession}',       [AiContentController::class, 'show'])->name('ai-content.show');
    Route::get('/ai-content/{aiContentSession}/status',[AiContentController::class, 'status'])->name('ai-content.status');
    Route::get('/ai-content/{aiContentSession}/items', [AiContentController::class, 'items'])->name('ai-content.items');
    Route::post('/ai-content/{aiContentSession}/push', [AiContentController::class, 'push'])->name('ai-content.push');
    Route::post('/ai-content/{aiContentSession}/translate', [AiContentController::class, 'translate'])->name('ai-content.translate');
    Route::delete('/ai-content/{aiContentSession}',    [AiContentController::class, 'destroy'])->name('ai-content.destroy');

    // Product Creation Request
    Route::prefix('product-requests')->name('product-requests.')->group(function () {
        Route::get('/',                        [ProductRequestController::class, 'index'])->name('index');
        Route::get('/list',                    [ProductRequestController::class, 'list'])->name('list');
        Route::post('/',                       [ProductRequestController::class, 'store'])->name('store');
        Route::post('/bulk',                   [ProductRequestController::class, 'bulk'])->name('bulk');
        Route::post('/sync-sheet',              [ProductRequestController::class, 'syncSheet'])->name('sync-sheet');

        Route::get('/my-tasks',                [ProductRequestController::class, 'myTasks'])->name('my-tasks');
        Route::get('/team-preview',            [ProductRequestController::class, 'teamPreview'])->name('team-preview');

        Route::get('/queue/{queue}',           [ProductRequestController::class, 'queue'])->name('queue')
            ->whereIn('queue', ['photoshoot', 'content']);

        // The Photoshoot Room: one calendar everyone reads, one person edits.
        Route::get('/photoshoot-room',                          [PhotoshootRoomController::class, 'index'])->name('photoshoot-room');
        Route::put('/photoshoot-room/{productRequest}',          [PhotoshootRoomController::class, 'update'])->name('photoshoot-room.update');

        Route::get('/notifications',           [ProductRequestController::class, 'notifications'])->name('notifications');
        Route::get('/notifications/feed',      [ProductRequestController::class, 'notificationFeed'])->name('notifications.feed');
        Route::post('/notifications/read',     [ProductRequestController::class, 'readNotifications'])->name('notifications.read');

        Route::get('/{productRequest}',                    [ProductRequestController::class, 'show'])->name('show');
        Route::get('/{productRequest}/status',             [ProductRequestController::class, 'status'])->name('status');
        Route::put('/{productRequest}',                    [ProductRequestController::class, 'update'])->name('update');
        Route::delete('/{productRequest}',                 [ProductRequestController::class, 'destroy'])->name('destroy');
        Route::get('/{productRequest}/activities',         [ProductRequestController::class, 'activities'])->name('activities');
        Route::get('/{productRequest}/skus/download',      [ProductRequestController::class, 'downloadSkus'])->name('skus.download');

        Route::post('/{productRequest}/revalidate',        [ProductRequestController::class, 'revalidate'])->name('revalidate');
        Route::post('/{productRequest}/skus',              [ProductRequestController::class, 'addSkus'])->name('skus.add');
        Route::post('/{productRequest}/transition',        [ProductRequestController::class, 'transition'])->name('transition');
        Route::post('/{productRequest}/continue-mapped',   [ProductRequestController::class, 'continueWithMapped'])->name('continue-mapped');
        Route::post('/{productRequest}/photoshoot',         [ProductRequestController::class, 'decidePhotoshoot'])->name('photoshoot-decision');
        Route::post('/{productRequest}/image-request',     [ProductRequestController::class, 'decideImageRequest'])->name('image-request-decision');
        Route::post('/{productRequest}/images-received',   [ProductRequestController::class, 'imagesReceived'])->name('images-received');
        Route::post('/{productRequest}/chase-mapping',     [ProductRequestController::class, 'chaseMapping'])->name('chase-mapping');
        Route::post('/{productRequest}/check-sheet-copy',  [ProductRequestController::class, 'checkSheetDescriptions'])->name('check-sheet-copy');
        Route::post('/{productRequest}/assign',            [ProductRequestController::class, 'assign'])->name('assign');
        Route::post('/{productRequest}/restaff',           [ProductRequestController::class, 'restaff'])->name('restaff');
        Route::post('/{productRequest}/claim',             [ProductRequestController::class, 'claim'])->name('claim');
        Route::post('/{productRequest}/reassign',          [ProductRequestController::class, 'reassign'])->name('reassign');
        Route::post('/{productRequest}/ai-content',        [ProductRequestController::class, 'generateAiContent'])->name('ai-content');
        Route::post('/{productRequest}/hold',              [ProductRequestController::class, 'hold'])->name('hold');
        Route::post('/{productRequest}/resume',            [ProductRequestController::class, 'resume'])->name('resume');
        Route::post('/{productRequest}/comment',           [ProductRequestController::class, 'comment'])->name('comment');
        Route::post('/{productRequest}/cancel',            [ProductRequestController::class, 'cancel'])->name('cancel');

        // Shopify draft products staged from the tracking sheet: build, review,
        // then push. The push is the only step that reaches Shopify.
        Route::post('/{productRequest}/drafts/build',                         [ProductRequestController::class, 'buildDrafts'])->name('drafts.build');
        Route::get('/{productRequest}/drafts/download',                       [ProductRequestController::class, 'downloadDrafts'])->name('drafts.download');
        Route::put('/{productRequest}/drafts/{draft}',                        [ProductRequestController::class, 'updateDraft'])->name('drafts.update');
        Route::delete('/{productRequest}/drafts/{draft}',                     [ProductRequestController::class, 'destroyDraft'])->name('drafts.destroy');
        Route::post('/{productRequest}/drafts/push',                          [ProductRequestController::class, 'pushDrafts'])->name('drafts.push');

        Route::post('/{productRequest}/attachments',                          [ProductRequestController::class, 'uploadAttachments'])->name('attachments.store');
        Route::get('/{productRequest}/attachments/{attachment}',              [ProductRequestController::class, 'downloadAttachment'])->name('attachments.download');
        Route::delete('/{productRequest}/attachments/{attachment}',           [ProductRequestController::class, 'destroyAttachment'])->name('attachments.destroy');
    });

    // Chat — messages live in the 'chat' cache store only, never in a table.
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        // Must stay above /{peer}, or the word gets bound as a user id.
        Route::get('/inbox', [ChatController::class, 'inbox'])->name('inbox');
        Route::get('/{peer}',           [ChatController::class, 'show'])->name('show');
        Route::get('/{peer}/messages',  [ChatController::class, 'messages'])->name('messages');
        Route::post('/{peer}/messages', [ChatController::class, 'send'])->name('send');
        Route::get('/{peer}/files/{token}', [ChatController::class, 'download'])->name('files.download');
        Route::post('/{peer}/typing',   [ChatController::class, 'typing'])->name('typing');
        Route::delete('/{peer}',        [ChatController::class, 'clear'])->name('clear');
    });

    // Shopify OAuth
    Route::get('/auth/shopify/redirect',   [ShopifyAuthController::class,  'redirect'])->name('shopify.auth.redirect');
    Route::get('/auth/shopify/callback',   [ShopifyAuthController::class,  'callback'])->name('shopify.auth.callback');

    // OneDrive OAuth
    // The Product Request sheet signs into its own Azure app, separate from the
    // shared OneDrive connection every other feature uses.
    Route::get('/auth/product-request-sheet/redirect',    [ProductRequestSheetAuthController::class, 'redirect'])->name('product-request-sheet.auth.redirect');
    Route::get('/auth/product-request-sheet/callback',    [ProductRequestSheetAuthController::class, 'callback'])->name('product-request-sheet.auth.callback');
    Route::post('/auth/product-request-sheet/disconnect', [ProductRequestSheetAuthController::class, 'disconnect'])->name('product-request-sheet.auth.disconnect');

    Route::get('/auth/onedrive/redirect',  [OneDriveAuthController::class, 'redirect'])->name('onedrive.auth.redirect');
    Route::get('/auth/onedrive/callback',  [OneDriveAuthController::class, 'callback'])->name('onedrive.auth.callback');
});
