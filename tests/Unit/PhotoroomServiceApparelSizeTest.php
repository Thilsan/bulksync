<?php

namespace Tests\Unit;

use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * Matching a Ghost Mannequin canvas to the photo's own shape.
 *
 * apparel_size used to default to SQUARE_HD for every redraw regardless of
 * what was photographed, a choice made only for resolution reasons and never
 * revisited for shape. A batch of floor-length gowns — naturally tall and
 * narrow, not square — came back with the garment recut by 49-69% across
 * several different SKUs, which is what a model does when it is asked to
 * fill a frame with a different proportion than the thing it is drawing.
 * closestApparelSize() picks whichever preset's own aspect ratio is nearest
 * to the photo's, so a gown gets a portrait canvas instead of a square one.
 */
class PhotoroomServiceApparelSizeTest extends TestCase
{
    public function test_a_square_photo_gets_the_square_preset(): void
    {
        $this->assertSame('SQUARE_HD', PhotoroomService::closestApparelSize(1000, 1000));
    }

    /** The shape this fix exists for: a floor-length gown, tall and narrow. */
    public function test_a_tall_gown_photo_gets_the_matching_portrait_preset(): void
    {
        $this->assertSame('PORTRAIT_HD_16_9', PhotoroomService::closestApparelSize(900, 1600));
    }

    public function test_a_moderately_tall_photo_gets_the_closer_portrait_preset(): void
    {
        $this->assertSame('PORTRAIT_HD_4_3', PhotoroomService::closestApparelSize(1200, 1600));
    }

    public function test_a_wide_photo_gets_a_landscape_preset(): void
    {
        $this->assertSame('LANDSCAPE_HD_16_9', PhotoroomService::closestApparelSize(1600, 900));
    }

    /** No dimensions to go on — the old, safe default. */
    public function test_missing_or_invalid_dimensions_fall_back_to_square(): void
    {
        $this->assertSame('SQUARE_HD', PhotoroomService::closestApparelSize(0, 0));
        $this->assertSame('SQUARE_HD', PhotoroomService::closestApparelSize(0, 1600));
        $this->assertSame('SQUARE_HD', PhotoroomService::closestApparelSize(1600, 0));
        $this->assertSame('SQUARE_HD', PhotoroomService::closestApparelSize(-100, 1600));
    }
}
