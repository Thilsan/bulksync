<?php

namespace Tests\Feature;

use App\Jobs\ScanOneDriveFolderJob;
use Tests\TestCase;

class ScanIdentifierDetectionTest extends TestCase
{
    public function test_a_folder_per_sku_uses_the_folder_name(): void
    {
        // The reliable case: files inside are named after barcodes or marketing
        // copy, so the folder is the only place the SKU appears.
        $this->assertIdentifiers(
            folder: 'LCM121COS01346',
            filename: '3614274195514_lip-idole-lip-shaper_50-sheiks.jpg',
            primary: 'LCM121COS01346',
            fallback: '3614274195514',
        );
    }

    public function test_a_flat_folder_of_sku_named_files_uses_the_filename(): void
    {
        $this->assertIdentifiers(
            folder: '',
            filename: 'LCM121COS01346.jpg',
            primary: 'LCM121COS01346',
            fallback: null,
        );
    }

    public function test_a_shipment_folder_still_offers_the_filename_sku_as_a_fallback(): void
    {
        // This is what used to come back No Match for every file in the batch:
        // the folder names the shipment, the file names the SKU.
        $this->assertIdentifiers(
            folder: 'Lancome Aug',
            filename: 'LCM121COS01346.jpg',
            primary: 'Lancome Aug',
            fallback: 'LCM121COS01346',
        );
    }

    public function test_suffixes_are_trimmed_from_both_names(): void
    {
        // "_0", "_1", "_2" shots of one SKU all resolve to the same identifier.
        $this->assertIdentifiers(
            folder: '',
            filename: '0000066897644_var2.jpg',
            primary: '0000066897644',
            fallback: null,
        );

        $this->assertIdentifiers(
            folder: 'LCM121COS01346_extra',
            filename: 'LCM121COS01346_1.jpg',
            primary: 'LCM121COS01346',
            fallback: null,
        );
    }

    public function test_no_fallback_when_both_names_agree(): void
    {
        $this->assertIdentifiers(
            folder: 'LCM121COS01346',
            filename: 'LCM121COS01346.jpg',
            primary: 'LCM121COS01346',
            fallback: null,
        );
    }

    public function test_a_filename_too_short_to_be_a_sku_is_not_offered(): void
    {
        // Sequence-numbered shots must not send the matcher looking for a
        // variant called "1".
        $this->assertIdentifiers(
            folder: 'LCM121COS01346',
            filename: '1.jpg',
            primary: 'LCM121COS01346',
            fallback: null,
        );
    }

    public function test_nested_folders_keep_the_outermost_folder_as_primary(): void
    {
        // streamPage passes the first-level folder down, so a deeper "Packshots"
        // sub-folder never becomes the identifier.
        $this->assertIdentifiers(
            folder: 'LCM121COS01346',
            filename: 'Packshot-front.jpg',
            primary: 'LCM121COS01346',
            fallback: 'Packshot',
        );
    }

    private function assertIdentifiers(
        string $folder,
        string $filename,
        string $primary,
        ?string $fallback,
    ): void {
        $this->assertSame(
            ['primary' => $primary, 'fallback' => $fallback],
            ScanOneDriveFolderJob::identifiersFor($folder, $filename),
        );
    }
}
