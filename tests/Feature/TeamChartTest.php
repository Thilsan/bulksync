<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The department work-distribution charts.
 *
 * One tab per department, and only Ecommerce has been transcribed. There is
 * no backend behind any of it, so what these tests hold is the tab bar: that
 * the chart with something on it is what you land on, that a department with
 * nothing on it says so rather than rendering an empty screen, and that a
 * junk tab in the URL cannot leave you looking at neither.
 */
class TeamChartTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // Inactive accounts are bounced to the login screen before any of
        // this runs, which reads as a routing failure and is not one.
        $this->staff = User::create([
            'name' => 'Rui Barbosa', 'email' => 'rui@example.test',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    private function get_tab(?string $tab = null): string
    {
        return $this->actingAs($this->staff)
            ->get(route('team.index', $tab ? ['tab' => $tab] : []))
            ->assertOk()
            ->getContent();
    }

    /** A section heading that exists nowhere but the transcribed matrix. */
    private const MATRIX = 'Website Ownership';

    public function test_the_ecommerce_chart_is_what_you_land_on(): void
    {
        $html = $this->get_tab();

        $this->assertStringContainsString('AIH E-Commerce Department', $html);
        $this->assertStringContainsString(self::MATRIX, $html);
    }

    /**
     * The department has an owner, and the chart says who. He heads the whole
     * sheet rather than a column of it, so he belongs above the matrix and not
     * in it — a name in the header that also appears as a person column would
     * claim he does the work as well as owns it.
     */
    public function test_the_ecommerce_chart_names_its_head_of_department(): void
    {
        $html = $this->get_tab();

        $this->assertStringContainsString('Head of Department', $html);
        $this->assertStringContainsString('Shahid Raufi', $html);

        $this->assertSame(
            1,
            substr_count($html, 'Shahid Raufi'),
            'The head of the department should be named once, above the matrix rather than inside it.',
        );
    }

    public function test_every_department_has_a_tab(): void
    {
        $html = $this->get_tab();

        foreach (['Ecommerce', 'Social Media', 'Marketing', 'CRM'] as $label) {
            $this->assertStringContainsString($label, $html, "Expected a {$label} tab.");
        }
    }

    /**
     * The three departments nobody has written down yet. A blank screen reads
     * as a tab that failed to load, so each one has to say what it is missing
     * — and must not leave the Ecommerce matrix standing under its heading.
     */
    public function test_a_department_with_no_chart_says_so(): void
    {
        foreach (['social-media' => 'Social Media', 'marketing' => 'Marketing', 'crm' => 'CRM'] as $key => $label) {
            $html = $this->get_tab($key);

            $this->assertStringContainsString($label . ' chart not added yet', $html);
            $this->assertStringNotContainsString(self::MATRIX, $html, "The {$label} tab is showing the Ecommerce matrix.");
        }
    }

    /** A tab nobody recognises lands on the one chart there is. */
    public function test_an_unknown_tab_falls_back_to_ecommerce(): void
    {
        $this->assertStringContainsString(self::MATRIX, $this->get_tab('no-such-department'));
    }

    /**
     * ?tab[] arrives as an array. Reading it as a string would be a 500 on a
     * page anyone can reach by editing the address bar.
     */
    public function test_an_array_in_the_tab_parameter_does_not_break_the_page(): void
    {
        $html = $this->actingAs($this->staff)
            ->get(route('team.index') . '?tab[]=crm')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::MATRIX, $html);
    }

    /**
     * It belongs to the management section rather than the top group: it is a
     * picture of who owns the department, not one of the tools people work in.
     * Position is the only thing that says so, so the position is held here.
     */
    public function test_the_sidebar_files_it_under_management(): void
    {
        $html = $this->get_tab();

        $link = strpos($html, '<span class="truncate">Team Chart</span>');
        $this->assertNotFalse($link, 'Could not find the Team Chart link.');

        $this->assertGreaterThan(
            $this->sectionHeading($html, 'Management'),
            $link,
            'Team Chart should sit under the Management heading.',
        );

        // Configuration rather than Media: this user holds no module
        // permissions, so Media is filtered out of their sidebar entirely.
        $this->assertLessThan(
            $this->sectionHeading($html, 'Configuration'),
            $link,
            'Team Chart should sit above the sections below Management.',
        );
    }

    /** Where a sidebar section heading starts in the page. */
    private function sectionHeading(string $html, string $label): int
    {
        $found = preg_match(
            '/tracking-\\[\\.14em\\] text-white\\/35">\\s*' . preg_quote($label, '/') . '\\s*</',
            $html,
            $match,
            PREG_OFFSET_CAPTURE,
        );

        $this->assertSame(1, $found, "Could not find the {$label} section heading in the sidebar.");

        return $match[0][1];
    }

    /** The sidebar calls it a chart, because that is what the page is. */
    public function test_the_sidebar_calls_it_team_chart(): void
    {
        $this->assertStringContainsString(
            '<span class="truncate">Team Chart</span>',
            $this->get_tab(),
        );
    }
}
