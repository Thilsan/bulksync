<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    /**
     * Page routes worth logging. Polling/status endpoints are deliberately
     * excluded so the log stays readable.
     */
    private const PAGES = [
        'dashboard'              => 'Dashboard',
        'upload.dashboard'       => 'Image Upload — Overview',
        'upload.create'          => 'Image Upload',
        'upload.history'         => 'Image Upload History',
        'upload.show'            => 'Image Upload — Session Details',
        'sku-checker.index'      => 'SKU Checker',
        'sku-checker.history'    => 'SKU Checker History',
        'sku-checker.show'       => 'SKU Check Results',
        'image-audit.index'      => 'Image Audit',
        'image-audit.show'       => 'Image Audit Results',
        'store-image-sync.index' => 'Store Image Migrate',
        'store-image-sync.show'  => 'Store Image Migrate — Progress',
        'metafield-update.index' => 'Metafield Checker',
        'metafield-update.status'=> 'Metafield Checker — Status',
        'ai-content.dashboard'   => 'AI Content — Overview',
        'ai-content.index'       => 'AI Content Generator',
        'ai-content.history'     => 'AI Content Sessions',
        'ai-content.show'        => 'AI Content — Review',
        'product-requests.index'            => 'Product Requests — Overview',
        'product-requests.list'             => 'Product Requests',
        'product-requests.my-tasks'         => 'My Tasks',
        'product-requests.photoshoot-room'  => 'Photoshoot Room',
        'product-requests.queue'            => 'Product Requests — Queue',
        'product-requests.show'             => 'a Product Request',
        'photo-editor.index'     => 'Photo Editor',
        'photo-editor.history'   => 'Photo Editor History',
        'photo-editor.show'      => 'Photo Editor — Session',
        'photo-editor.configure' => 'Photo Editor — Configure',
        'barcode-images.index'   => 'Barcode Images',
        'barcode-images.history' => 'Barcode Images History',
        'barcode-images.show'    => 'Barcode Images — Session',
        'collection-content.index' => 'Collection SEO',
        'collection-content.show'  => 'Collection SEO — Session',
        'seo-audit.index'              => 'SEO Audit',
        'seo-audit.show'               => 'SEO Audit Results',
        'seo-audit.striking-distance'  => 'SEO Audit — Almost Ranking',
        'seo-audit.impact'             => 'SEO Audit — Impact',
        'orders.dashboard'       => 'Management Dashboard',
        'chat.index'             => 'Chat',
        'team.index'             => 'Team',
        'stores.index'           => 'Stores',
        'settings.index'         => 'Settings',
        'super-admin.index'      => 'Admin Panel',
        'super-admin.activity'   => 'Activity Log',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (Auth::check() && $request->isMethod('GET')) {
            $routeName = $request->route()?->getName();

            if ($routeName && isset(self::PAGES[$routeName])) {
                ActivityLog::record(ActivityLog::ACTION_PAGE_VIEW, self::PAGES[$routeName]);
            }
        }

        return $response;
    }
}
