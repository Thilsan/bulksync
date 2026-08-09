<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Cegid mapping lookup
    |---------------------------------------------------------------------------
    |
    | Product Creation Requests need to know whether a SKU has been mapped in
    | Cegid by the Supply Chain team. There is no Cegid API wired into this app
    | yet, so the module ships with the "none" driver: mapping is recorded
    | manually by Supply Chain from the request screen, and Shopify presence is
    | the only automatic signal.
    |
    | Switch to "http" and fill in the endpoint below once Cegid exposes a SKU
    | lookup. Nothing else in the module has to change — the workflow, the
    | re-check schedule and the status badges all read through CegidService.
    |
    | Supported drivers: "none", "http"
    |
    */

    'driver' => env('CEGID_DRIVER', 'none'),

    'http' => [
        // Should accept POST {"skus": [...]} and return {"<sku>": true|false, ...}
        // or {"data": {"<sku>": true|false}}. Adjust CegidService::viaHttp() if
        // the real endpoint disagrees.
        'endpoint' => env('CEGID_ENDPOINT'),
        'token'    => env('CEGID_TOKEN'),
        'timeout'  => (int) env('CEGID_TIMEOUT', 30),

        // Cegid lookups are batched; keep this under whatever the endpoint allows.
        'batch_size' => (int) env('CEGID_BATCH_SIZE', 100),
    ],

    // How long a Cegid answer stays cached. Mapping changes are not urgent —
    // the scheduled re-check picks them up within the hour.
    'cache_ttl' => (int) env('CEGID_CACHE_TTL', 900),

];
