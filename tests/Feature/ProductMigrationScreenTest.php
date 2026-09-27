<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The migration screen draws what it does rather than describing it: source
 * store, target store, and the SKUs that travel between them.
 *
 * It used to carry an info banner and a three-step "What happens" list, each
 * written twice — once per migration type — which is four paragraphs explaining
 * an arrow. The page had no test at all, so nothing held the form together
 * while that came out.
 */
class ProductMigrationScreenTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::create([
            'name' => 'Migration Operator', 'email' => 'migrate@example.test',
            'password' => 'password', 'is_active' => true, 'perm_store_sync' => true,
        ]);

        $user->stores()->attach(
            Store::create([
                'name' => 'Bluesalon Website', 'shopify_domain' => 'blue.myshopify.com', 'is_active' => true,
            ])->id
        );

        return $user;
    }

    public function test_the_screen_keeps_every_field_the_run_is_started_from(): void
    {
        $page = $this->actingAs($this->operator())
            ->get(route('store-image-sync.index'))
            ->assertOk();

        // The four inputs the controller reads. A redesign that drops one of
        // these leaves a form that looks right and posts an incomplete run.
        $page->assertSee('name="migration_type"', false)
             ->assertSee('name="from_store"', false)
             ->assertSee('name="to_store"', false)
             ->assertSee('name="skus"', false)
             ->assertSee('name="csv_file"', false);
    }

    public function test_both_migration_types_are_offered_with_the_consequence_that_differs(): void
    {
        $page = $this->actingAs($this->operator())
            ->get(route('store-image-sync.index'))
            ->assertOk();

        $page->assertSee('Images only')
             ->assertSee('Full product')
             // Creating drafts in a live store is the one thing worth saying
             // out loud, so it survives any trimming of the page's prose.
             ->assertSee('drafts', false);
    }

    public function test_the_stores_it_can_move_between_are_the_ones_this_user_holds(): void
    {
        $operator = $this->operator();

        Store::create([
            'name' => 'Someone Elses Store', 'shopify_domain' => 'other.myshopify.com', 'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->get(route('store-image-sync.index'))
            ->assertOk()
            ->assertSee('Bluesalon Website')
            ->assertDontSee('Someone Elses Store');
    }
}
