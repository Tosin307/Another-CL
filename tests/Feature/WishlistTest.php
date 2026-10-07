<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WishlistBoard;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_customer_receives_one_empty_permanent_general_wishlist(): void
    {
        $customer = User::factory()->create();

        $response = $this->actingAs($customer)->getJson('/api/wishlist');

        $response->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonCount(1, 'wishlists')
            ->assertJsonPath('wishlists.0.name', 'General Wishlist')
            ->assertJsonPath('wishlists.0.is_general', true)
            ->assertJsonCount(0, 'wishlists.0.items');
        $this->assertDatabaseCount('wishlist_boards', 1);
        $this->assertDatabaseMissing('wishlist_boards', ['name' => 'Everyday Edit']);
        $this->assertDatabaseCount('wishlist_items', 0);
    }

    public function test_guests_and_unverified_customers_cannot_use_account_wishlist_routes(): void
    {
        $this->getJson('/api/wishlist')->assertUnauthorized();
        $this->postJson('/api/wishlist/boards', ['name' => 'Birthday Looks'])->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->getJson('/api/wishlist')->assertForbidden();
    }

    public function test_customer_can_manage_custom_boards_but_not_general_wishlist(): void
    {
        $customer = User::factory()->create();
        $generalId = $this->actingAs($customer)->getJson('/api/wishlist')->json('general_wishlist_id');

        $created = $this->postJson('/api/wishlist/boards', ['name' => 'Birthday Looks'])
            ->assertCreated();
        $customId = collect($created->json('wishlists'))->firstWhere('name', 'Birthday Looks')['id'];

        $this->patchJson("/api/wishlist/boards/{$customId}", ['name' => 'December Looks'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'December Looks']);

        $this->patchJson("/api/wishlist/boards/{$generalId}", ['name' => 'Renamed'])
            ->assertUnprocessable();
        $this->deleteJson("/api/wishlist/boards/{$generalId}")
            ->assertUnprocessable();

        $this->deleteJson("/api/wishlist/boards/{$customId}")
            ->assertOk()
            ->assertJsonCount(1, 'wishlists')
            ->assertJsonPath('wishlists.0.name', 'General Wishlist');
    }

    public function test_guest_merge_preserves_account_items_and_deduplicates_exact_variants(): void
    {
        $customer = User::factory()->create();
        $generalId = $this->actingAs($customer)->getJson('/api/wishlist')->json('general_wishlist_id');

        $this->postJson('/api/wishlist/items', $this->item('the-sade-sculpted-dress', 'Cobalt', '10', $generalId))
            ->assertCreated()
            ->assertJsonPath('count', 1);

        $response = $this->postJson('/api/wishlist/merge', [
            'items' => [
                $this->mergeItem('the-sade-sculpted-dress', 'Cobalt', '10'),
                $this->mergeItem('the-lola-contrast-jumpsuit', 'Pink / Blue', '12'),
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('merged', 1)
            ->assertJsonPath('count', 2);
        $this->assertDatabaseCount('wishlist_items', 2);
        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $customer->id,
            'product_key' => 'the-sade-sculpted-dress',
            'colour' => 'Cobalt',
            'size' => '10',
        ]);
        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $customer->id,
            'product_key' => 'the-lola-contrast-jumpsuit',
        ]);
    }

    public function test_legacy_customer_boards_are_preserved_during_first_account_merge(): void
    {
        $customer = User::factory()->create();

        $response = $this->actingAs($customer)->postJson('/api/wishlist/merge', [
            'boards' => [
                ['legacy_id' => 'birthday-looks-local', 'name' => 'Birthday Looks'],
            ],
            'items' => [
                array_merge($this->mergeItem('the-imani-folded-dress', 'Coral', '12'), [
                    'legacy_wishlist_id' => 'birthday-looks-local',
                ]),
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('merged', 1)
            ->assertJsonFragment(['name' => 'Birthday Looks']);
        $custom = WishlistBoard::where('user_id', $customer->id)->where('name', 'Birthday Looks')->firstOrFail();
        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $customer->id,
            'wishlist_board_id' => $custom->id,
            'product_key' => 'the-imani-folded-dress',
        ]);
    }

    public function test_customer_wishlist_records_are_strictly_isolated(): void
    {
        $customerA = User::factory()->create();
        $customerB = User::factory()->create();
        $generalA = $this->actingAs($customerA)->getJson('/api/wishlist')->json('general_wishlist_id');
        $this->postJson('/api/wishlist/items', $this->item('the-sade-sculpted-dress', 'Cobalt', '10', $generalA))
            ->assertCreated();
        $itemA = WishlistItem::where('user_id', $customerA->id)->firstOrFail();

        $this->actingAs($customerB)->getJson('/api/wishlist')
            ->assertOk()
            ->assertJsonPath('count', 0);
        $this->deleteJson("/api/wishlist/items/{$itemA->id}")->assertNotFound();
        $this->patchJson("/api/wishlist/items/{$itemA->id}", ['quantity' => 2])->assertNotFound();

        $this->assertDatabaseHas('wishlist_items', [
            'id' => $itemA->id,
            'user_id' => $customerA->id,
        ]);
    }

    public function test_deleting_custom_board_moves_its_items_to_general_without_loss(): void
    {
        $customer = User::factory()->create();
        $state = $this->actingAs($customer)->getJson('/api/wishlist');
        $generalId = $state->json('general_wishlist_id');
        $created = $this->postJson('/api/wishlist/boards', ['name' => 'Office Dresses']);
        $customId = collect($created->json('wishlists'))->firstWhere('name', 'Office Dresses')['id'];
        $this->postJson('/api/wishlist/items', $this->item('the-kemi-column-dress', 'Berry', '14', $customId))
            ->assertCreated();

        $this->deleteJson("/api/wishlist/boards/{$customId}")
            ->assertOk()
            ->assertJsonPath('count', 1);
        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $customer->id,
            'wishlist_board_id' => $generalId,
            'product_key' => 'the-kemi-column-dress',
        ]);
    }

    public function test_admin_has_read_only_wishlist_ownership_overview_and_customer_is_forbidden(): void
    {
        $customer = User::factory()->create(['name' => 'Wishlist Customer']);
        $this->actingAs($customer)->getJson('/api/wishlist');

        $ordinaryCustomer = User::factory()->create();
        $this->actingAs($ordinaryCustomer)->get('/admin/wishlists')->assertForbidden();

        $admin = User::factory()->create(['email' => 'wishlist-admin@example.com']);
        Gate::before(fn (User $user, string $ability) => $user->is($admin) && $ability === 'admin' ? true : null);

        $this->actingAs($admin)->get('/admin/wishlists')
            ->assertOk()
            ->assertViewIs('admin.wishlists')
            ->assertSee('Wishlist Intelligence')
            ->assertSee('Customer wishlist contents remain controlled only from the authenticated storefront.')
            ->assertSee('Data coverage');
    }

    private function item(string $productKey, string $colour, string $size, string $wishlistId): array
    {
        return array_merge($this->mergeItem($productKey, $colour, $size), [
            'wishlist_id' => (int) $wishlistId,
        ]);
    }

    private function mergeItem(string $productKey, string $colour, string $size): array
    {
        return [
            'product_key' => $productKey,
            'name' => ucwords(str_replace('-', ' ', $productKey)),
            'category' => 'Dress',
            'price' => 38500,
            'added_price' => 38500,
            'image' => '/shop-products/test.webp',
            'colours' => [['name' => $colour, 'value' => '#822743', 'images' => ['/shop-products/test.webp']]],
            'sizes' => ['8', '10', '12', '14'],
            'colour' => $colour,
            'size' => $size,
            'quantity' => 1,
            'availability' => 'In stock',
            'note' => 'A genuine customer selection.',
            'notify_price_drop' => false,
        ];
    }
}
