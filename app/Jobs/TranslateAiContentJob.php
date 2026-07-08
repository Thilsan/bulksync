<?php

namespace App\Jobs;

use App\Models\AiContentSession;
use App\Services\FanarService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TranslateAiContentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(public readonly int $sessionId) {}

    public function handle(FanarService $fanar): void
    {
        $session = AiContentSession::find($this->sessionId);
        if (!$session) return;

        $items = $session->items()
            ->with('images')
            ->whereIn('status', ['done', 'pushed'])
            ->get();

        $session->update([
            'status'          => 'translating',
            'total_items'     => $items->count(),
            'processed_items' => 0,
        ]);

        try {
            foreach ($items as $item) {
                try {
                    if ($item->ai_description) {
                        $item->ai_description_ar = $fanar->translateToArabic($item->ai_description, 'preserve_html');
                        sleep(2);
                    }
                    if ($item->ai_meta_title) {
                        $item->ai_meta_title_ar = $fanar->translateToArabic($item->ai_meta_title, 'default');
                        sleep(2);
                    }
                    if ($item->ai_meta_description) {
                        $item->ai_meta_description_ar = $fanar->translateToArabic($item->ai_meta_description, 'default');
                        sleep(2);
                    }
                    $item->save();

                    foreach ($item->images as $image) {
                        if ($image->ai_alt_text) {
                            $image->update(['ai_alt_text_ar' => $fanar->translateToArabic($image->ai_alt_text, 'default')]);
                            sleep(2);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('AiContent item translation failed', ['item' => $item->id, 'error' => $e->getMessage()]);
                }

                $session->increment('processed_items');
            }

            $session->update(['status' => 'ready']);
        } catch (\Throwable $e) {
            Log::error('TranslateAiContentJob failed', ['session' => $this->sessionId, 'error' => $e->getMessage()]);
            $session->update(['status' => 'ready', 'error_message' => 'Translation failed: ' . $e->getMessage()]);
        }
    }
}
