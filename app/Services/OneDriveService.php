<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class OneDriveService
{
    /**
     * Deliberately narrowed back to Files.Read.
     *
     * Files.Read.All would let us read a folder somebody else shared — Graph
     * answers 403 on their link with Files.Read alone — but the Blue Salon
     * tenant does not allow users to consent for themselves, so it needs a
     * directory admin to grant it. Until that happens Files.Read.All refuses
     * every refresh with AADSTS65001 and nothing works at all, which is worse
     * than not supporting shared links. Admin consent was requested on
     * 2026-08-17 (~2 day turnaround) — widen it again once the tenant admin
     * confirms Files.Read.All shows a granted status in the app registration's
     * API permissions.
     *
     * Changing this line invalidates every stored refresh token: they carry the
     * scopes they were consented under, so a refresh asking for anything more is
     * refused. Every user has to click "Reconnect OneDrive" after a change here.
     *
     * Both the initial consent and every token refresh must ask for the same
     * scopes, so they live here rather than in two places that can drift.
     */
    public const SCOPES = 'Files.Read offline_access User.Read';

    /**
     * Settings prefix for the Product Creation Request automation's own Azure app.
     *
     * That sheet lives in one person's OneDrive and is read by a background job,
     * not by whoever is looking at the screen — so it signs in once, as itself,
     * through its own app registration. Keeping it separate from the shared app
     * means rotating one cannot break the other, and the account that owns the
     * sheet does not have to be given a login here at all.
     */
    public const PRODUCT_REQUEST_PROFILE = 'pcr_onedrive';

    private Client $http;
    private ?string $accessToken   = null;
    private float   $tokenExpiry   = 0.0;
    private ?User   $user          = null;
    private ?string $serviceProfile = null;

    private array $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'avif'];

    public function __construct()
    {
        $this->http = new Client(['timeout' => 60]);
    }

    /**
     * Sign in as a stored service account instead of a user.
     *
     * Its credentials, and the token it holds, live in Settings under the given
     * prefix — nothing about it touches the shared app or anybody's account.
     */
    public function asServiceAccount(string $profile = self::PRODUCT_REQUEST_PROFILE): static
    {
        $this->serviceProfile = $profile;
        $this->user           = null;
        $this->accessToken    = null;
        $this->tokenExpiry    = 0.0;

        return $this;
    }

    public function setUser(User $user): static
    {
        $this->user           = $user;
        $this->serviceProfile = null;
        return $this;
    }

    /**
     * Stream images from a shared OneDrive folder one page at a time.
     * Calls $callback($file) for each image — never holds all files in memory.
     *
     * $file = [
     *   'filename'   => 'SKU-123.jpg',
     *   'drive_id'   => 'b!...',
     *   'item_id'    => '01...',
     *   'size_bytes' => 123456,
     * ]
     */
    /**
     * A small preview of one file, straight from Graph.
     *
     * The configure screen has to show the operator each SKU's photos before a
     * single Photoroom credit is spent, and the originals are ~9 MB apiece —
     * pulling those to build thumbnails would cost minutes and gigabytes for a
     * screen nobody has decided anything on yet. Graph renders previews for
     * free, so they are fetched instead.
     *
     * Returns null rather than throwing: a missing preview should leave a
     * placeholder in the grid, not take the page down.
     */
    public function thumbnailBytes(string $driveId, string $itemId, string $size = 'large'): ?string
    {
        $size = in_array($size, ['small', 'medium', 'large'], true) ? $size : 'large';

        try {
            $token = $this->getAccessToken();

            $response = $this->http->get(
                "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$itemId}/thumbnails/0/{$size}/content",
                ['headers' => ['Authorization' => "Bearer {$token}"], 'allow_redirects' => true],
            );

            $content = (string) $response->getBody();

            return $content !== '' && $this->isImageBytes($content) ? $content : null;
        } catch (\Throwable $e) {
            Log::warning("OneDrive: no thumbnail for {$itemId}: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Resolve a share link to the drive + item id it points at, the same way
     * every other method here reaches a shared file — just returned instead
     * of consumed, for callers that need to make more than one Graph call
     * against the same item (e.g. reading several worksheets in one run).
     *
     * @return array{driveId: string, itemId: string}
     */
    public function resolveShareItem(string $shareUrl): array
    {
        $token   = $this->getAccessToken();
        $encoded = $this->encodeShareUrl($shareUrl);

        $response = $this->http->get(
            "https://graph.microsoft.com/v1.0/shares/{$encoded}/driveItem",
            ['headers' => ['Authorization' => "Bearer {$token}"]],
        );

        $item = json_decode((string) $response->getBody(), true);

        return [
            'driveId' => $item['parentReference']['driveId'] ?? '',
            'itemId'  => $item['id'] ?? '',
        ];
    }

    /**
     * Every cell value in a worksheet's used range, as plain PHP values —
     * row 0 is the header. valuesOnly=true skips formatting/formulas, which
     * is all a sync job ever needs and keeps the response far smaller.
     */
    public function worksheetValues(string $driveId, string $itemId, string $worksheetName): array
    {
        try {
            return $this->usedRange($driveId, $itemId, $worksheetName);
        } catch (\Throwable $e) {
            // A tab is addressed by a name somebody typed, and "Home " is not
            // "Home" to Graph — it is a 404 that reads like a missing tab. The
            // sheet is not ours to tidy, so match what is actually there.
            $actual = $this->matchWorksheetName($driveId, $itemId, $worksheetName);

            if ($actual === null || $actual === $worksheetName) {
                throw $e;
            }

            Log::info("OneDriveService: worksheet \"{$worksheetName}\" resolved to \"{$actual}\".");

            return $this->usedRange($driveId, $itemId, $actual);
        }
    }

    private function usedRange(string $driveId, string $itemId, string $worksheetName): array
    {
        $name = rawurlencode($worksheetName);

        $response = $this->retrying(
            fn () => $this->http->get(
                "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$itemId}/workbook/worksheets('{$name}')/usedRange(valuesOnly=true)",
                ['headers' => ['Authorization' => "Bearer {$this->getAccessToken()}"]],
            ),
            "usedRange({$worksheetName})",
        );

        $data = json_decode((string) $response->getBody(), true);

        return $data['values'] ?? [];
    }

    /**
     * Retry a Graph call that failed for a reason that is not our fault.
     *
     * Reading a large worksheet sometimes takes longer than Graph is willing to
     * wait and it answers 504 MaxRequestDurationExceeded — nothing is wrong with
     * the request, it simply has to be asked again. Throttling (429) and the odd
     * 503 are the same. Left unhandled, one of these aborted an entire sheet sync
     * partway through, which is a far worse outcome than waiting a few seconds.
     *
     * A 404 or a 401 is not retried: asking again cannot change the answer.
     */
    private function retrying(callable $call, string $what, int $attempts = 3)
    {
        $transient = [429, 500, 502, 503, 504, 509];

        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (\GuzzleHttp\Exception\BadResponseException $e) {
                $status = $e->getResponse()?->getStatusCode();

                if ($attempt >= $attempts || !in_array($status, $transient, true)) {
                    throw $e;
                }

                // Graph says how long to wait when it is throttling; otherwise
                // back off, because an immediate retry usually times out again.
                $wait = (int) ($e->getResponse()?->getHeaderLine('Retry-After') ?: 0) ?: (5 * $attempt);

                Log::warning("OneDriveService: {$what} failed with {$status} — retrying in {$wait}s (attempt {$attempt} of {$attempts}).");

                sleep($wait);
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                if ($attempt >= $attempts) {
                    throw $e;
                }

                Log::warning("OneDriveService: {$what} could not connect — retrying (attempt {$attempt} of {$attempts}).");

                sleep(5 * $attempt);
            }
        }
    }

    /** The workbook's own spelling of a tab, ignoring case and spacing. */
    private function matchWorksheetName(string $driveId, string $itemId, string $wanted): ?string
    {
        $flatten = fn (string $name) => strtolower(preg_replace('/[^a-z0-9]/i', '', $name));

        try {
            foreach ($this->worksheetNames($driveId, $itemId) as $name) {
                if ($flatten($name) === $flatten($wanted)) {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('OneDriveService: could not list worksheets — ' . $e->getMessage());
        }

        return null;
    }

    /**
     * The workbook's worksheet names, exactly as Excel holds them.
     *
     * Worth having because a tab is addressed by name and a name is typed by a
     * person: "Home" and "Home " are different worksheets to Graph, and the
     * only symptom is a 404 that says nothing about which.
     *
     * @return array<int, string>
     */
    public function worksheetNames(string $driveId, string $itemId): array
    {
        $token = $this->getAccessToken();

        $response = $this->http->get(
            "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$itemId}/workbook/worksheets",
            ['headers' => ['Authorization' => "Bearer {$token}"]],
        );

        $data = json_decode((string) $response->getBody(), true);

        return array_column($data['value'] ?? [], 'name');
    }

    public function streamFolderImages(string $shareUrl, callable $callback): void
    {
        $token   = $this->getAccessToken();
        $encoded = $this->encodeShareUrl($shareUrl);

        try {
            $this->streamPage(
                "https://graph.microsoft.com/v1.0/shares/{$encoded}/driveItem/children?\$top=200",
                $token,
                $callback,
                '', // root level — no folder name yet
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException('OneDrive API error: ' . $e->getMessage());
        }
    }

    /**
     * Download a file by drive + item ID.
     * Strategy (in order — stops at first valid image bytes):
     *   1. Stored pre-auth URL from scan (no token needed, fast)
     *   2. /me/drive/items/{itemId}/content — most reliable for user's own OneDrive
     *   3. Fresh @microsoft.graph.downloadUrl fetched from item metadata
     *   4. /drives/{driveId}/items/{itemId}/content — last resort
     */
    public function downloadFileById(string $driveId, string $itemId, string $downloadUrl = ''): string
    {
        if (!$itemId) {
            throw new \RuntimeException("Missing OneDrive item ID — cannot download file.");
        }

        // 1. Try the pre-auth URL stored during scan
        if ($downloadUrl) {
            $content = $this->tryDownloadUrl($downloadUrl);
            if ($content !== null) {
                Log::info("OneDrive: downloaded via stored pre-auth URL ({$itemId})");
                return $content;
            }
            Log::warning("OneDrive: stored pre-auth URL not valid for {$itemId}, trying auth endpoints");
        }

        $token = $this->getAccessToken();

        // 2. /me/drive — the most reliable path when the token belongs to the file's owner
        try {
            $response = $this->http->get(
                "https://graph.microsoft.com/v1.0/me/drive/items/{$itemId}/content",
                ['headers' => ['Authorization' => "Bearer {$token}"], 'allow_redirects' => true]
            );
            $content = (string) $response->getBody();
            if (!empty($content) && $this->isImageBytes($content)) {
                Log::info("OneDrive: downloaded via /me/drive ({$itemId}, " . strlen($content) . " bytes)");
                return $content;
            }
            Log::warning("OneDrive: /me/drive returned non-image for {$itemId}, first bytes: " . bin2hex(substr($content, 0, 8)));
        } catch (\Throwable $e) {
            Log::warning("OneDrive: /me/drive failed for {$itemId}: " . $e->getMessage());
        }

        // 3. Fetch fresh @microsoft.graph.downloadUrl from item metadata
        if ($driveId) {
            try {
                $response = $this->http->get(
                    "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$itemId}",
                    ['headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']]
                );
                $meta     = json_decode((string) $response->getBody(), true);
                $freshUrl = $meta['@microsoft.graph.downloadUrl'] ?? '';

                if ($freshUrl) {
                    $content = $this->tryDownloadUrl($freshUrl);
                    if ($content !== null) {
                        Log::info("OneDrive: downloaded via fresh pre-auth URL ({$itemId})");
                        return $content;
                    }
                    Log::warning("OneDrive: fresh pre-auth URL non-image for {$itemId}");
                } else {
                    Log::warning("OneDrive: no @microsoft.graph.downloadUrl in metadata for {$itemId}");
                }
            } catch (\Throwable $e) {
                Log::warning("OneDrive: metadata fetch failed for {$itemId}: " . $e->getMessage());
            }

            // 4. Direct /drives/{driveId}/items/{itemId}/content
            try {
                $response = $this->http->get(
                    "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$itemId}/content",
                    ['headers' => ['Authorization' => "Bearer {$token}"], 'allow_redirects' => true]
                );
                $content = (string) $response->getBody();
                if (!empty($content) && $this->isImageBytes($content)) {
                    Log::info("OneDrive: downloaded via /drives endpoint ({$itemId})");
                    return $content;
                }
                Log::error("OneDrive: /drives /content non-image for {$itemId}: " . substr($content, 0, 200));
            } catch (\Throwable $e) {
                Log::warning("OneDrive: /drives /content failed for {$itemId}: " . $e->getMessage());
            }
        }

        throw new \RuntimeException(
            "All OneDrive download methods failed for item {$itemId}. " .
            "Check Render logs for first-bytes details."
        );
    }

    private function tryDownloadUrl(string $url): ?string
    {
        try {
            $response = $this->http->get($url, ['allow_redirects' => true, 'timeout' => 60]);
            $content  = (string) $response->getBody();
            if (!empty($content) && $this->isImageBytes($content)) {
                return $content;
            }
        } catch (\Throwable $e) {
            Log::warning("OneDrive: download URL request failed: " . $e->getMessage());
        }
        return null;
    }

    private function isImageBytes(string $content): bool
    {
        if (strlen($content) < 4) {
            return false;
        }

        if (str_starts_with($content, "\xFF\xD8")             // JPEG
            || str_starts_with($content, "\x89PNG")           // PNG
            || str_starts_with($content, "GIF")               // GIF
            || str_starts_with($content, "BM")                // BMP
            || str_starts_with($content, "\x49\x49\x2A\x00") // TIFF LE
            || str_starts_with($content, "\x4D\x4D\x00\x2A") // TIFF BE
            || str_contains(substr($content, 0, 16), "WEBP")   // WebP
        ) {
            return true;
        }

        return self::isAvif($content);
    }

    /**
     * AVIF, which has no magic number at byte zero.
     *
     * It is an ISO base-media file like MP4: the first box is a length, then
     * "ftyp", then the brands that say what the file actually is. So the brand
     * is what gets checked, not the box — HEIC and video share the container
     * and are not files this pipeline can open.
     *
     * Missing this was not a cosmetic gap. Every download path here is gated on
     * isImageBytes, so an AVIF was fetched successfully four times over, judged
     * not to be an image each time, and reported as "all download methods
     * failed" — a network-sounding error for a file that had arrived intact.
     * The scanner had already accepted the extension, so the two halves
     * disagreed about whether AVIF was supported at all.
     */
    private static function isAvif(string $content): bool
    {
        if (substr($content, 4, 4) !== 'ftyp') {
            return false;
        }

        // Major brand sits at byte 8; compatible brands follow it, and a file
        // branded "mif1" can still declare "avif" among them.
        $brands = substr($content, 8, 24);

        return str_contains($brands, 'avif') || str_contains($brands, 'avis');
    }

    /**
     * Test that credentials work.
     */
    public function testConnection(): bool
    {
        try {
            $this->checkConnection();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Same check, but the reason comes back instead of being swallowed.
     *
     * "Connection failed. Check Azure credentials." was the answer to every
     * cause — an expired secret, a revoked consent, a sign-in that never
     * finished — and each of those is fixed somewhere different.
     *
     * @throws \RuntimeException  with something a person can act on
     */
    public function checkConnection(): void
    {
        try {
            $this->getAccessToken();
        } catch (\Throwable $e) {
            Log::error('OneDrive connection test failed: ' . $e->getMessage());
            throw $e;
        }
    }

    // ──────────────────────────────────────────────────────────────────────

    private function streamPage(string $url, string $token, callable $callback, string $folderName = ''): void
    {
        $response = $this->http->get($url, [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Accept'        => 'application/json',
            ],
        ]);

        $data  = json_decode((string) $response->getBody(), true);
        $items = $data['value'] ?? [];

        foreach ($items as $item) {
            ['driveId' => $driveId, 'itemId' => $itemId] = self::locateItem($item);

            if (isset($item['folder']) || isset($item['remoteItem']['folder'])) {
                // Recurse into sub-folders, passing the folder name as the SKU context
                if ($itemId && $driveId) {
                    $childUrl = "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$itemId}/children?\$top=200";
                    // Use this folder's name as the SKU for files inside it
                    $childFolderName = $folderName ?: $item['name'];
                    try {
                        $this->streamPage($childUrl, $token, $callback, $childFolderName);
                    } catch (\Throwable $e) {
                        Log::warning("OneDrive: could not scan sub-folder [{$item['name']}]: " . $e->getMessage());
                    }
                }
                continue;
            }

            $name = $item['name'] ?? '';
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($ext, $this->imageExtensions)) {
                continue;
            }

            $callback([
                'filename'     => $name,
                'folder_name'  => $folderName,
                'drive_id'     => $driveId,
                'item_id'      => $itemId,
                'size_bytes'   => $item['size'] ?? 0,
                'download_url' => $item['@microsoft.graph.downloadUrl'] ?? '',
            ]);
        }

        // Follow pagination
        if (!empty($data['@odata.nextLink'])) {
            $token = $this->getAccessToken();
            $this->streamPage($data['@odata.nextLink'], $token, $callback, $folderName);
        }
    }

    /**
     * Work out which drive an item really lives in, and its id there.
     *
     * Anything reached through a share appears as a remoteItem: the outer id
     * and parentReference describe the shortcut sitting in our own drive,
     * while the file itself lives in the sender's. Following the outer pair
     * would ask our drive for an id it has never heard of, so when there is a
     * remoteItem it is the only pair worth reading — never a mix of the two.
     *
     * @return array{driveId: string, itemId: string}
     */
    public static function locateItem(array $item): array
    {
        $source = isset($item['remoteItem']) ? $item['remoteItem'] : $item;

        return [
            'driveId' => $source['parentReference']['driveId'] ?? '',
            'itemId'  => $source['id'] ?? '',
        ];
    }

    private function getAccessToken(): string
    {
        // Return cached token if still valid
        if ($this->accessToken && microtime(true) < ($this->tokenExpiry - 60)) {
            return $this->accessToken;
        }

        if ($this->serviceProfile) {
            return $this->serviceAccountToken();
        }

        $user         = $this->user ?? auth()->user();
        $storedExpiry = (int) ($user?->onedrive_token_expiry ?? 0);

        // Use stored access token if still valid
        if ($storedExpiry > time() + 60) {
            $token = $user?->onedrive_access_token;
            if ($token) {
                $this->accessToken = $token;
                $this->tokenExpiry = (float) $storedExpiry;
                return $this->accessToken;
            }
        }

        // Refresh using refresh token
        $refreshToken = $user?->onedrive_refresh_token;
        $clientId     = Setting::get('onedrive_client_id');
        $clientSecret = Setting::get('onedrive_client_secret');

        // Each of these is a different problem with a different fix, so say which.
        if (!$clientId || !$clientSecret) {
            throw new \RuntimeException('The Azure app credentials are missing. A super admin needs to fill in the Client ID and Client Secret under Settings → Azure App Credentials.');
        }

        if (!$refreshToken) {
            throw new \RuntimeException('This account has no OneDrive refresh token, so the connection cannot be renewed — the sign-in either never completed or was revoked. Click "Reconnect OneDrive".');
        }

        $tenantId = Setting::get('onedrive_tenant_id') ?: 'common';

        $data = $this->refreshToken($tenantId, $clientId, $clientSecret, $refreshToken);

        $newExpiry = (string) (time() + ($data['expires_in'] ?? 3600));

        $user?->update([
            'onedrive_access_token'  => $data['access_token'],
            'onedrive_refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'onedrive_token_expiry'  => $newExpiry,
        ]);

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = (float) $newExpiry;

        return $this->accessToken;
    }

    /**
     * The same exchange for a service account, whose credentials and token live
     * in Settings rather than on a user row.
     */
    private function serviceAccountToken(): string
    {
        $profile = $this->serviceProfile;
        $expiry  = (int) Setting::get("{$profile}_token_expiry");

        if ($expiry > time() + 60 && ($stored = Setting::get("{$profile}_access_token"))) {
            $this->accessToken = $stored;
            $this->tokenExpiry = (float) $expiry;

            return $this->accessToken;
        }

        $clientId     = Setting::get("{$profile}_client_id");
        $clientSecret = Setting::get("{$profile}_client_secret");
        $refreshToken = Setting::get("{$profile}_refresh_token");

        // Each of these is a different problem with a different fix, so say which.
        if (!$clientId || !$clientSecret) {
            throw new \RuntimeException(
                'The tracking sheet has no Azure app configured. A super admin needs to fill in its '
                . 'Tenant ID, Client ID and Client Secret under Settings → Product Request Sheet Access.'
            );
        }

        if (!$refreshToken) {
            throw new \RuntimeException(
                'Nobody has signed the tracking sheet account in yet. A super admin needs to press '
                . '"Connect the sheet account" under Settings → Product Request Sheet Access.'
            );
        }

        $data      = $this->refreshToken(Setting::get("{$profile}_tenant_id") ?: 'common', $clientId, $clientSecret, $refreshToken);
        $newExpiry = (string) (time() + ($data['expires_in'] ?? 3600));

        Setting::set("{$profile}_access_token", $data['access_token']);
        Setting::set("{$profile}_refresh_token", $data['refresh_token'] ?? $refreshToken);
        Setting::set("{$profile}_token_expiry", $newExpiry);

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = (float) $newExpiry;

        return $this->accessToken;
    }

    /** Trade a refresh token for a fresh access token. */
    private function refreshToken(string $tenantId, string $clientId, string $clientSecret, string $refreshToken): array
    {
        try {
            $response = $this->http->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                [
                    'form_params' => [
                        'grant_type'    => 'refresh_token',
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                        'refresh_token' => $refreshToken,
                        'scope'         => self::SCOPES,
                    ],
                ]
            );
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Microsoft says exactly what is wrong — an AADSTS code and a
            // sentence explaining it. Guzzle truncates that into its own
            // message, so pull it out of the response body instead: "check your
            // credentials" sends people looking in the wrong place.
            $body  = (string) $e->getResponse()?->getBody();
            $azure = json_decode($body, true);

            throw new \RuntimeException(
                'Microsoft refused the connection: '
                . ($azure['error_description'] ?? $azure['error'] ?? $e->getMessage()),
                0,
                $e,
            );
        }

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Failed to refresh OneDrive token. Please reconnect in Settings.');
        }

        return $data;
    }

    private function encodeShareUrl(string $url): string
    {
        $base64 = base64_encode($url);
        $base64 = rtrim($base64, '=');
        $base64 = str_replace(['+', '/'], ['-', '_'], $base64);

        return 'u!' . $base64;
    }
}
