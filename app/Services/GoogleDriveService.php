<?php

namespace App\Services;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    private Client $http;
    private string $apiKey;

    private const BASE   = 'https://www.googleapis.com/drive/v3';
    private const PAGE_SIZE = 1000; // max allowed by Google Drive API

    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/tiff',
        'image/avif',
    ];

    public function __construct()
    {
        $this->apiKey = Setting::get('google_drive_api_key', config('services.google_drive.api_key', ''));
        $this->http   = new Client(['timeout' => 60]);
    }

    /**
     * Stream every image file in a publicly-shared Google Drive folder.
     * Calls $callback($file) for each image — never loads all files into memory.
     *
     * $file = [
     *   'filename'   => 'SKU-123.jpg',
     *   'drive_id'   => 'google',   // marker — unused for download
     *   'item_id'    => '1a2b3c…',  // Google Drive file ID
     *   'size_bytes' => 123456,
     * ]
     */
    public function streamFolderImages(string $shareUrl, callable $callback): void
    {
        if (!$this->apiKey) {
            throw new \RuntimeException('Google Drive API key is not configured. Go to Settings.');
        }

        $folderId = $this->extractFolderId($shareUrl);

        if (!$folderId) {
            throw new \RuntimeException(
                'Could not extract a folder ID from the URL. ' .
                'Expected format: https://drive.google.com/drive/folders/FOLDER_ID'
            );
        }

        Log::info("GoogleDriveService: scanning folder {$folderId}");

        $this->listPage($folderId, null, $callback);
    }

    /**
     * Download a file from Google Drive by its file ID.
     * Works for publicly shared files ("Anyone with the link can view").
     * $driveId is ignored (kept for interface compatibility with OneDrive).
     */
    public function downloadFileById(string $driveId, string $fileId): string
    {
        // Primary: use the Drive API v3 media download endpoint
        try {
            $response = $this->http->get(self::BASE . "/files/{$fileId}", [
                'query'           => ['alt' => 'media', 'key' => $this->apiKey],
                'allow_redirects' => true,
            ]);

            return (string) $response->getBody();

        } catch (ClientException $e) {
            // Fallback: direct export download URL (bypasses virus-scan warning for larger files)
            Log::warning("GoogleDriveService: API download failed for {$fileId}, trying direct URL. Error: " . $e->getMessage());

            $response = $this->http->get('https://drive.google.com/uc', [
                'query'           => ['export' => 'download', 'id' => $fileId, 'confirm' => 't'],
                'allow_redirects' => true,
                'headers'         => ['User-Agent' => 'Mozilla/5.0'],
            ]);

            return (string) $response->getBody();
        }
    }

    /**
     * Test that the API key is valid and working.
     */
    public function testConnection(): bool
    {
        if (!$this->apiKey) {
            return false;
        }

        try {
            $response = $this->http->get(self::BASE . '/about', [
                'query' => ['key' => $this->apiKey, 'fields' => 'kind'],
            ]);

            return $response->getStatusCode() === 200;

        } catch (\Throwable $e) {
            Log::error('Google Drive connection test failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Validate and extract the folder ID from various Google Drive URL formats.
     *
     * Supported formats:
     *   https://drive.google.com/drive/folders/1Q203aaXAii_xFBlaO4P8_Od5CYCW3TmB
     *   https://drive.google.com/drive/folders/1Q203aaXAii_xFBlaO4P8_Od5CYCW3TmB?usp=sharing
     *   https://drive.google.com/open?id=1Q203aaXAii_xFBlaO4P8_Od5CYCW3TmB
     *   https://drive.google.com/folderview?id=1Q203aaXAii_xFBlaO4P8_Od5CYCW3TmB
     */
    public function extractFolderId(string $url): string
    {
        // /drive/folders/{id}
        if (preg_match('#/folders/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }

        // ?id={id} or &id={id}
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }

        return '';
    }

    // ──────────────────────────────────────────────────────────────────────

    private function listPage(string $folderId, ?string $pageToken, callable $callback): void
    {
        $params = [
            'key'      => $this->apiKey,
            'q'        => "'{$folderId}' in parents and trashed = false",
            'fields'   => 'nextPageToken,files(id,name,size,mimeType)',
            'pageSize' => self::PAGE_SIZE,
            'orderBy'  => 'name',
        ];

        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        try {
            $response = $this->http->get(self::BASE . '/files', ['query' => $params]);
            $data     = json_decode((string) $response->getBody(), true);

        } catch (ClientException $e) {
            $body = (string) $e->getResponse()->getBody();
            $err  = json_decode($body, true)['error']['message'] ?? $e->getMessage();
            throw new \RuntimeException("Google Drive API error: {$err}");
        }

        if (isset($data['error'])) {
            throw new \RuntimeException('Google Drive API error: ' . $data['error']['message']);
        }

        foreach ($data['files'] ?? [] as $file) {
            // Skip non-images (folders, docs, etc.)
            if (!in_array($file['mimeType'] ?? '', self::IMAGE_MIMES)) {
                continue;
            }

            $callback([
                'filename'   => $file['name'],
                'drive_id'   => 'google',
                'item_id'    => $file['id'],
                'size_bytes' => (int) ($file['size'] ?? 0),
            ]);
        }

        // Follow pagination until all pages are consumed
        if (!empty($data['nextPageToken'])) {
            $this->listPage($folderId, $data['nextPageToken'], $callback);
        }
    }
}
