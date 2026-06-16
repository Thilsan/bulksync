<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\OneDriveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        $settings = [
            'onedrive_client_id'     => Setting::get('onedrive_client_id'),
            'onedrive_client_secret' => Setting::get('onedrive_client_secret'),
            'onedrive_connected'     => (bool) Setting::get('onedrive_access_token'),
        ];

        return view('settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'onedrive_client_id'     => ['nullable', 'string', 'max:255'],
            'onedrive_client_secret' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::set($key, $value ?: null);
        }

        return back()->with('success', 'Settings saved successfully.');
    }

    public function testOnedrive(): \Illuminate\Http\JsonResponse
    {
        $ok = app(OneDriveService::class)->testConnection();

        return response()->json(['ok' => $ok, 'message' => $ok ? 'OneDrive connected!' : 'Connection failed. Check Azure credentials.']);
    }
}
