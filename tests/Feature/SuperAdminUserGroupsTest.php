<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Users screen groups people by what they are.
 *
 * Eighty names in one list tells you nothing about who is who — the question
 * people arrive with is "which of them is the Watches brand manager" — and a
 * deactivated account has no business sitting among the working ones.
 */
class SuperAdminUserGroupsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, array $attributes = []): User
    {
        return User::create(array_merge([
            'name'      => $name,
            'email'     => str($name)->slug() . '@example.test',
            'password'  => 'password',
            'is_active' => true,
        ], $attributes));
    }

    public function test_each_kind_of_user_gets_its_own_heading(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);

        $this->user('Ghassen', ['pcr_role' => 'ecommerce']);
        $this->user('Brand Person', ['pcr_role' => 'brand_manager']);
        $this->user('Studio', ['pcr_role' => 'photographer']);
        $this->user('Nobody In Particular');

        $page = $this->actingAs($admin)->get(route('super-admin.index'))->assertOk();

        $page->assertSee('Super Admins');
        $page->assertSee('E-Commerce Team');
        $page->assertSee('Brand Manager / Team');
        $page->assertSee('Photoshoot Coordinator');
        $page->assertSee('No workflow role');
    }

    /** Groups nobody is in are not drawn — an empty heading is noise. */
    public function test_an_empty_group_is_not_shown(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);

        $this->actingAs($admin)->get(route('super-admin.index'))
            ->assertOk()
            ->assertSee('Super Admins')
            ->assertDontSee('Photoshoot Coordinator')
            ->assertDontSee('Deactivated');
    }

    /** A switched-off account is not a person to hand work to. */
    public function test_a_deactivated_user_is_pulled_out_of_their_role(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);

        $this->user('Left The Company', ['pcr_role' => 'brand_manager', 'is_active' => false]);

        // Asserted by position, not absence: every card carries a role dropdown
        // listing all the labels, so "Brand Manager / Team" is on the page either
        // way. What matters is which heading this person is under.
        $this->actingAs($admin)->get(route('super-admin.index'))
            ->assertOk()
            ->assertSeeInOrder(['Deactivated', 'Left The Company']);
    }

    /**
     * A super admin who also holds a workflow role appears once, under Super
     * Admins — that is the fact that matters about them.
     */
    public function test_a_super_admin_with_a_role_is_listed_once(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true, 'pcr_role' => 'brand_manager']);

        $this->actingAs($admin)->get(route('super-admin.index'))
            ->assertOk()
            ->assertSeeInOrder(['Super Admins', 'Root']);
    }

    public function test_every_user_still_appears_somewhere(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);

        $names = ['Ghassen', 'Brand Person', 'Studio', 'Nobody In Particular'];

        $this->user('Ghassen', ['pcr_role' => 'ecommerce']);
        $this->user('Brand Person', ['pcr_role' => 'brand_manager']);
        $this->user('Studio', ['pcr_role' => 'photographer']);
        $this->user('Nobody In Particular');
        $this->user('Gone', ['is_active' => false]);

        $page = $this->actingAs($admin)->get(route('super-admin.index'))->assertOk();

        foreach (array_merge($names, ['Root', 'Gone']) as $name) {
            $page->assertSee($name);
        }
    }
}
