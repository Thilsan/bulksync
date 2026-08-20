<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The photoshoot is run from the Photoshoot Room, which has the calendar and the
 * studio detail My Tasks cannot show. Listing it in both places makes one job
 * look like two, each missing something the other has.
 */
class MyTasksPhotoshootTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->store = Store::create([
            'name' => 'Bluesalon Website', 'shopify_domain' => 'qatarbluesalon.myshopify.com', 'is_active' => true,
        ]);
    }

    private function user(string $name, ?string $role = null): User
    {
        $user = User::create([
            'name'                 => $name,
            'email'                => str($name)->slug() . '@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
            'pcr_role'             => $role,
        ]);

        $user->stores()->attach($this->store->id);

        return $user;
    }

    private function request(User $owner, string $brand = 'HANRO'): ProductRequest
    {
        return ProductRequest::create([
            'reference'           => ProductRequest::nextReference(),
            'user_id'             => $owner->id,
            'store_id'            => $this->store->id,
            'request_type'        => 'new_brand',
            'brand'               => $brand,
            'category'            => 'Lingerie',
            'status'              => ProductRequest::WAITING_IMAGES,
            'priority'            => 'medium',
            'photoshoot_required' => true,
            'total_skus'          => 3,
        ]);
    }

    public function test_a_photoshoot_assignment_does_not_appear_on_my_tasks(): void
    {
        $coordinator = $this->user('Ghassen', 'photographer');
        $request     = $this->request($coordinator, 'PHOTOSHOOT ONLY');

        app(ProductRequestWorkflow::class)->assignRole(
            request: $request, field: 'photographer_id', userId: $coordinator->id, notify: false,
        );

        $this->actingAs($coordinator)
            ->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertDontSee('PHOTOSHOOT ONLY')
            ->assertSee('Photoshoot Room');
    }

    /** Holding another role on the same request still puts it on the list. */
    public function test_a_second_role_on_the_same_request_still_shows(): void
    {
        $person  = $this->user('Ghassen', 'photographer');
        $request = $this->request($person, 'ALSO MINE');

        $workflow = app(ProductRequestWorkflow::class);
        $workflow->assignRole(request: $request, field: 'photographer_id', userId: $person->id, notify: false);
        $workflow->assignRole(request: $request, field: 'assigned_to', userId: $person->id, notify: false);

        $this->actingAs($person)
            ->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee('ALSO MINE');
    }

    /** Every other role is unaffected. */
    public function test_an_ecommerce_assignment_still_appears(): void
    {
        $person  = $this->user('Ahmed', 'ecommerce');
        $request = $this->request($person, 'MINE TO RUN');

        app(ProductRequestWorkflow::class)->assignRole(
            request: $request, field: 'assigned_to', userId: $person->id, notify: false,
        );

        $this->actingAs($person)
            ->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee('MINE TO RUN');
    }

    /**
     * Handing a request to somebody else moves it off your list and onto theirs.
     *
     * The old assignment is closed rather than overwritten, so the history of who
     * held it survives — but a closed assignment must not keep the request on the
     * previous holder's desk.
     */
    public function test_reassigning_moves_the_request_off_the_old_holders_list(): void
    {
        $ahamed  = $this->user('Ahamed', 'ecommerce');
        $ghassen = $this->user('Ghassen', 'ecommerce');
        $request = $this->request($ahamed, 'HANDED OVER');

        $workflow = app(ProductRequestWorkflow::class);
        $workflow->assignRole(request: $request, field: 'assigned_to', userId: $ahamed->id, notify: false);

        $this->actingAs($ahamed)->get(route('product-requests.my-tasks'))->assertSee('HANDED OVER');

        // Reassigned from the Team Assignments panel.
        $workflow->assignRole(request: $request->refresh(), field: 'assigned_to', userId: $ghassen->id, notify: false);

        $this->actingAs($ahamed)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertDontSee('HANDED OVER');

        $this->actingAs($ghassen)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee('HANDED OVER');
    }

    /** Still yours while you hold any other role on it. */
    public function test_giving_away_one_role_keeps_a_request_you_hold_another_on(): void
    {
        $ahamed  = $this->user('Ahamed', 'ecommerce');
        $ghassen = $this->user('Ghassen', 'ecommerce');
        $request = $this->request($ahamed, 'STILL MINE');

        $workflow = app(ProductRequestWorkflow::class);
        $workflow->assignRole(request: $request, field: 'assigned_to', userId: $ahamed->id, notify: false);
        $workflow->assignRole(request: $request->refresh(), field: 'supply_chain_id', userId: $ahamed->id, notify: false);

        // The E-Commerce hat goes to Ghassen; Supply Chain stays with Ahamed.
        $workflow->assignRole(request: $request->refresh(), field: 'assigned_to', userId: $ghassen->id, notify: false);

        $this->actingAs($ahamed)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee('STILL MINE');
    }

    /**
     * The unclaimed-work list is skipped too: a coordinator picking up a shoot
     * does it from the room, where the date can be set at the same time.
     */
    public function test_unclaimed_photoshoot_work_is_not_listed_either(): void
    {
        $coordinator = $this->user('Ghassen', 'photographer');
        $this->request($coordinator, 'NOBODY HAS THIS');

        $this->actingAs($coordinator)
            ->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertDontSee('NOBODY HAS THIS');
    }
}
