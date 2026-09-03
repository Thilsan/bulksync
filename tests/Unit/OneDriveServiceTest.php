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

    /**
     * The bug behind "All OneDrive download methods failed" on .avif files.
     *
     * Every download path is gated on this check, so a file it does not
     * recognise is fetched successfully and thrown away four times over, then
     * reported as a network failure. The scanner accepts .avif, so the two
     * halves of the service disagreed about whether AVIF was supported.
     */
    public function test_avif_bytes_are_recognised_as_an_image(): void
    {
        $this->assertTrue($this->isImage($this->avifHeader('avif')));
    }

    /** An AVIF sequence is still an AVIF. */
    public function test_an_avif_sequence_is_recognised(): void
    {
        $this->assertTrue($this->isImage($this->avifHeader('avis')));
    }

    /**
     * A file branded "mif1" can still name avif among its compatible brands,
     * which is how some encoders write them.
     */
    public function test_avif_declared_as_a_compatible_brand_is_recognised(): void
    {
        $this->assertTrue($this->isImage($this->avifHeader('mif1', 'mif1avif')));
    }

    /**
     * The container is shared with video and HEIC. Recognising "ftyp" alone
     * would hand an MP4 to the image pipeline and fail somewhere less obvious.
     */
    public function test_other_files_in_the_same_container_are_not_treated_as_images(): void
    {
        $this->assertFalse($this->isImage($this->avifHeader('isom', 'isomiso2mp41')));
        $this->assertFalse($this->isImage($this->avifHeader('heic', 'heicmif1')));
    }

    /** The formats that already worked must keep working. */
    public function test_the_existing_formats_are_still_recognised(): void
    {
        $this->assertTrue($this->isImage("\xFF\xD8\xFF\xE0" . str_repeat('x', 20)), 'JPEG');
        $this->assertTrue($this->isImage("\x89PNG\r\n\x1A\n" . str_repeat('x', 20)), 'PNG');
        $this->assertTrue($this->isImage('RIFF' . str_repeat('x', 4) . 'WEBPVP8 '), 'WebP');
        $this->assertFalse($this->isImage('<!DOCTYPE html><html>'), 'an error page is not an image');
        $this->assertFalse($this->isImage('ab'), 'too short to tell');
    }

    /** A 'ftyp' box: 4-byte length, the tag, the major brand, then the rest. */
    private function avifHeader(string $majorBrand, string $compatibleBrands = ''): string
    {
        return pack('N', 32) . 'ftyp' . $majorBrand . pack('N', 0)
            . ($compatibleBrands ?: $majorBrand) . str_repeat("\x00", 16);
    }

    private function isImage(string $bytes): bool
    {
        $method = new \ReflectionMethod(OneDriveService::class, 'isImageBytes');
        $method->setAccessible(true);

        return $method->invoke(new OneDriveService(), $bytes);
    }
}
