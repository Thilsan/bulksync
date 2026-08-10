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
     * Files.Read covers only the signed-in account's OWN files, which is not
     * enough for a folder someone else shared with us — Graph answers 403 on
     * their link. Files.Read.All is "everything this account can already
     * reach", which is what a share link is. It grants no more than the
     * person could open in a browser themselves, and needs no admin consent.
     *
     * Both the initial consent and every token refresh must ask for the same
     * scopes, so they live here rather than in two places that can drift.
     */
    public const SCOPES = 'Files.Read.All offline_access User.Read';

    private Client $http;
    private ?string $accessToken   = null;
    private float   $tokenExpiry   = 0.0;
    private ?User   $user          = null;

    private array $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'avif'];

    public function __construct()
    {
        $this->http = new Client(['timeout' => 60]);
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
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
        return str_starts_with($content, "\xFF\xD8")          // JPEG
            || str_starts_with($content, "\x89PNG")           // PNG
            || str_starts_with($content, "GIF")               // GIF
            || str_starts_with($content, "BM")                // BMP
            || str_starts_with($content, "\x49\x49\x2A\x00") // TIFF LE
            || str_starts_with($content, "\x4D\x4D\x00\x2A") // TIFF BE
            || str_contains(substr($content, 0, 16), "WEBP"); // WebP
    }

    /**
     * Test that credentials work.
     */
    public function testConnection(): bool
    {
        try {
            $this->getAccessToken();
            return true;
        } catch (\Throwable $e) {
            Log::error('OneDrive connection test failed: ' . $e->getMessage());
            return false;
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

        if (!$refreshToken || !$clientId || !$clientSecret) {
            throw new \RuntimeException('OneDrive is not connected. Go to Settings and click "Connect OneDrive".');
        }

        $tenantId = Setting::get('onedrive_tenant_id') ?: 'common';

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

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Failed to refresh OneDrive token. Please reconnect in Settings.');
        }

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

    private function encodeShareUrl(string $url): string
    {
        $base64 = base64_encode($url);
        $base64 = rtrim($base64, '=');
        $base64 = str_replace(['+', '/'], ['-', '_'], $base64);

        return 'u!' . $base64;
    }
}
