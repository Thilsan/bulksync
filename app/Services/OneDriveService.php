<?php

namespace App\Services;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class OneDriveService
{
    private Client $http;
    private ?string $accessToken   = null;
    private float   $tokenExpiry   = 0.0;

    private array $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'avif'];

    public function __construct()
    {
        $this->http = new Client(['timeout' => 60]);
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
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException('OneDrive API error: ' . $e->getMessage());
        }
    }

    /**
     * Download a file directly by drive + item ID.
     * This never expires — it uses the authenticated Graph API endpoint.
     */
    public function downloadFileById(string $driveId, string $itemId): string
    {
        $token = $this->getAccessToken();

        // The /content endpoint returns a redirect to the actual download URL
        $response = $this->http->get(
            "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$itemId}/content",
            [
                'headers'         => ['Authorization' => "Bearer {$token}"],
                'allow_redirects' => true,
            ]
        );

        return (string) $response->getBody();
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

    private function streamPage(string $url, string $token, callable $callback): void
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
            if (isset($item['folder'])) {
                // Recurse into sub-folders
                if (isset($item['id'], $item['parentReference']['driveId'])) {
                    $driveId  = $item['parentReference']['driveId'];
                    $childUrl = "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$item['id']}/children?\$top=200";
                    try {
                        $this->streamPage($childUrl, $token, $callback);
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

            // Prefer the parentReference driveId; fall back to the root driveId
            $driveId = $item['parentReference']['driveId']
                ?? $item['remoteItem']['parentReference']['driveId']
                ?? '';

            $callback([
                'filename'   => $name,
                'drive_id'   => $driveId,
                'item_id'    => $item['id'] ?? '',
                'size_bytes' => $item['size'] ?? 0,
            ]);
        }

        // Follow pagination (@odata.nextLink) — Graph returns max 200 per page
        if (!empty($data['@odata.nextLink'])) {
            // Refresh access token if near expiry before next page
            $token = $this->getAccessToken();
            $this->streamPage($data['@odata.nextLink'], $token, $callback);
        }
    }

    private function getAccessToken(): string
    {
        // Refresh 60 seconds before actual expiry
        if ($this->accessToken && microtime(true) < ($this->tokenExpiry - 60)) {
            return $this->accessToken;
        }

        $tenantId     = Setting::get('onedrive_tenant_id',     config('services.onedrive.tenant_id', ''));
        $clientId     = Setting::get('onedrive_client_id',     config('services.onedrive.client_id', ''));
        $clientSecret = Setting::get('onedrive_client_secret', config('services.onedrive.client_secret', ''));

        if (!$tenantId || !$clientId || !$clientSecret) {
            throw new \RuntimeException('OneDrive credentials are not configured. Go to Settings.');
        }

        $response = $this->http->post(
            "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            [
                'form_params' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ],
            ]
        );

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Failed to obtain OneDrive access token: ' . json_encode($data));
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = microtime(true) + ($data['expires_in'] ?? 3600);

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
