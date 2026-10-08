<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The monthly history is split on the billing day, not the 1st.
 *
 * Photoroom's allowance resets on the day the plan was last changed — the 8th,
 * after the October upgrade. So a run on the 7th and a run on the 9th fall in
 * different allowances while sitting in the same calendar month, and a
 * calendar grouping would put them together and never reconcile with the
 * dashboard.
 */
class PhotoEditorBillingMonthsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.photoroom.cycle_day' => 8, 'services.photoroom.api_key' => 'live_sk_test']);
        Http::fake(['*/v2/account' => Http::response('', 503)]);
    }

    /** Entities decoded: the view writes &ndash;, which strip_tags leaves alone. */
    private function flatten(string $html): string
    {
        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function editor(): User
    {
        return User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
    }

    private function edited(User $user, string $on, int $count): void
    {
        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true],
        ]);

        for ($i = 0; $i < $count; $i++) {
            $item = PhotoEditItem::create([
                'photo_edit_session_id' => $session->id,
                'kind'                  => 'cutout',
                'filename'              => "{$on}-{$i}.jpg",
                'sku_detected'          => 'SKU-1',
                'status'                => 'edited',
                'onedrive_drive_id'     => 'd',
                'onedrive_item_id'      => "i-{$on}-{$i}",
            ]);

            // created_at is not fillable, so it has to be set afterwards with
            // the timestamps off — passing it to create() silently leaves the
            // row on today's date, which is what this test is about.
            $item->timestamps = false;
            $item->created_at = Carbon::parse($on);
            $item->updated_at = Carbon::parse($on);
            $item->save();
        }
    }

    public function test_the_7th_and_the_9th_fall_in_different_periods(): void
    {
        $user = $this->editor();

        $this->edited($user, '2026-10-07', 3);   // the period that began 8 September
        $this->edited($user, '2026-10-09', 5);   // the one that began 8 October

        $flat = $this->flatten(
            $this->actingAs($user)->get(route('photo-editor.history'))->assertOk()->getContent()
        );

        $this->assertMatchesRegularExpression('/8 Oct – 7 Nov 2026 current 5/u', $flat);
        $this->assertMatchesRegularExpression('/8 Sep – 7 Oct 2026 3/u', $flat);
    }

    /** The 8th itself opens a period rather than closing one. */
    public function test_the_billing_day_belongs_to_the_period_it_opens(): void
    {
        $user = $this->editor();

        $this->edited($user, '2026-10-08', 2);

        $flat = $this->flatten($this->actingAs($user)->get(route('photo-editor.history'))->getContent());

        $this->assertMatchesRegularExpression('/8 Oct – 7 Nov 2026 current 2/u', $flat);
    }

    /**
     * Only what this person is allowed to see.
     *
     * The totals above the table are already scoped; a monthly count that
     * quietly summed the whole company would be a different number next to
     * them and neither would explain the other.
     */
    public function test_it_counts_only_the_viewers_own_runs(): void
    {
        $mine   = $this->editor();
        $theirs = $this->editor();

        $this->edited($mine, '2026-10-09', 4);
        $this->edited($theirs, '2026-10-09', 11);

        $flat = $this->flatten($this->actingAs($mine)->get(route('photo-editor.history'))->getContent());

        $this->assertMatchesRegularExpression('/8 Oct – 7 Nov 2026 current 4/u', $flat);
    }
}
