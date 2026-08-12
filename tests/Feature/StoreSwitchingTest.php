<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The active website belongs to the person, not the company — switching used
 * to be one boolean on the stores table and moved everybody at once.
 */
class StoreSwitchingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, bool $superAdmin = false): User
    {
        return User::create([
            'name'           => $email,
            'email'          => $email,
            'password'       => 'password',
            'is_active'      => true,
            'is_super_admin' => $superAdmin,
        ]);
    }

    private function store(string $name): Store
    {
        return Store::create(['name' => $name, 'shopify_domain' => str($name)->slug() . '.myshopify.com']);
    }

    public function test_switching_stores_does_not_move_anyone_else(): void
    {
        $alpha = $this->store('Alpha');
        $beta  = $this->store('Beta');

        $ann = $this->user('ann@example.test');
        $bob = $this->user('bob@example.test');

        $ann->stores()->attach([$alpha->id, $beta->id]);
        $bob->stores()->attach([$alpha->id, $beta->id]);

        $this->actingAs($ann)->post(route('stores.switch', $beta))->assertRedirect();

        $this->assertSame($beta->id, Store::getActive($ann->id)?->id);
        $this->assertSame($alpha->id, Store::getActive($bob->id)?->id, 'Bob should still be in his own store.');
    }

    public function test_a_user_cannot_switch_into_a_store_they_have_no_access_to(): void
    {
        $alpha   = $this->store('Alpha');
        $offlimits = $this->store('Off Limits');

        $ann = $this->user('ann@example.test');
        $ann->stores()->attach($alpha->id);

        $this->actingAs($ann)->post(route('stores.switch', $offlimits))->assertForbidden();

        $this->assertSame($alpha->id, Store::getActive($ann->id)?->id);
    }

    public function test_losing_access_to_the_chosen_store_falls_back_instead_of_leaking_it(): void
    {
        $alpha = $this->store('Alpha');
        $beta  = $this->store('Beta');

        $ann = $this->user('ann@example.test');
        $ann->stores()->attach([$alpha->id, $beta->id]);

        $this->actingAs($ann)->post(route('stores.switch', $beta));

        $ann->stores()->detach($beta->id);

        $this->assertSame($alpha->id, Store::getActive($ann->fresh()->id)?->id);
    }

    /** The switcher, the store list and the dashboard all read the new field. */
    public function test_the_screens_that_show_the_active_store_still_render(): void
    {
        $alpha = $this->store('Alpha');
        $beta  = $this->store('Beta');

        $ann = $this->user('ann@example.test');
        $ann->stores()->attach([$alpha->id, $beta->id]);

        $this->actingAs($ann)->post(route('stores.switch', $beta));

        $this->actingAs($ann)->get(route('stores.index'))->assertOk()->assertSee('Beta');
        $this->actingAs($ann)->get(route('dashboard'))->assertOk();
    }

    public function test_deleting_a_store_releases_the_people_sitting_in_it(): void
    {
        $alpha = $this->store('Alpha');
        $beta  = $this->store('Beta');

        $admin = $this->user('admin@example.test', superAdmin: true);

        $this->actingAs($admin)->post(route('stores.switch', $beta));
        $this->actingAs($admin)->delete(route('stores.destroy', $beta))->assertRedirect();

        $this->assertNull($admin->fresh()->active_store_id);
        $this->assertSame($alpha->id, Store::getActive($admin->id)?->id);
    }

    public function test_super_admins_each_keep_their_own_store(): void
    {
        $alpha = $this->store('Alpha');
        $beta  = $this->store('Beta');

        $one = $this->user('one@example.test', superAdmin: true);
        $two = $this->user('two@example.test', superAdmin: true);

        $this->actingAs($one)->post(route('stores.switch', $beta))->assertRedirect();

        $this->assertSame($beta->id, Store::getActive($one->id)?->id);
        $this->assertSame($alpha->id, Store::getActive($two->id)?->id);
    }
}
