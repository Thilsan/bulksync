<?php

namespace Tests\Unit;

use App\Services\OneDriveService;
use PHPUnit\Framework\TestCase;

class OneDriveServiceTest extends TestCase
{
    public function test_an_item_in_our_own_drive_is_located_by_its_own_ids(): void
    {
        $located = OneDriveService::locateItem([
            'id'              => '01OWNITEM',
            'name'            => 'SPB251146.jpg',
            'parentReference' => ['driveId' => 'b!ourdrive'],
        ]);

        $this->assertSame(['driveId' => 'b!ourdrive', 'itemId' => '01OWNITEM'], $located);
    }

    public function test_a_shared_item_is_located_in_the_senders_drive_not_ours(): void
    {
        // What Graph returns for a folder someone else shared with us: the
        // outer ids belong to the shortcut in our drive, the remoteItem to
        // the real thing in theirs.
        $located = OneDriveService::locateItem([
            'id'              => '01SHORTCUT',
            'name'            => 'SS25 Dresses',
            'parentReference' => ['driveId' => 'b!ourdrive'],
            'remoteItem'      => [
                'id'              => '01REALFOLDER',
                'folder'          => ['childCount' => 12],
                'parentReference' => ['driveId' => 'b!theirdrive'],
            ],
        ]);

        $this->assertSame(['driveId' => 'b!theirdrive', 'itemId' => '01REALFOLDER'], $located);
    }

    public function test_a_partial_item_yields_empty_strings_rather_than_a_wrong_pairing(): void
    {
        $this->assertSame(
            ['driveId' => '', 'itemId' => ''],
            OneDriveService::locateItem(['name' => 'orphan.jpg']),
        );

        // A remoteItem with no drive must not silently fall back to our own.
        $this->assertSame(
            ['driveId' => '', 'itemId' => '01REAL'],
            OneDriveService::locateItem([
                'id'              => '01SHORTCUT',
                'parentReference' => ['driveId' => 'b!ourdrive'],
                'remoteItem'      => ['id' => '01REAL'],
            ]),
        );
    }

    public function test_the_requested_scopes_stay_within_what_a_user_can_consent_to(): void
    {
        // Files.Read.All needs a directory admin in this tenant, and asking for a
        // scope nobody has granted breaks every refresh, not just shared links.
        $this->assertStringContainsString('Files.Read', OneDriveService::SCOPES);
        $this->assertStringNotContainsString('Files.Read.All', OneDriveService::SCOPES);

        // Without offline_access there is no refresh token, so the connection
        // would die an hour after every sign-in.
        $this->assertStringContainsString('offline_access', OneDriveService::SCOPES);
    }
}
