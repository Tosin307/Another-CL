<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WishlistBoard;
use App\Models\WishlistEvent;
use App\Models\WishlistItem;
use App\Services\WishlistFunnelTracker;
use App\Services\WishlistEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class WishlistIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_customer_actions_create_events_but_refresh_and_merge_do_not_fake_saves(): void
    {
        $customer = User::factory()->create();
        $general = $this->actingAs($customer)->getJson('/api/wishlist')->json('general_wishlist_id');
        $this->getJson('/api/wishlist')->assertOk();
        $this->assertDatabaseCount('wishlist_events', 0);

        $response = $this->postJson('/api/wishlist/items', $this->item('the-sade-sculpted-dress', 'Cobalt', '14', $general));
        $response->assertCreated();
        $itemId = (int) $response->json('wishlists.0.items.0.id');
        $this->assertDatabaseHas('wishlist_events', ['event_type' => 'product_saved', 'wishlist_item_id' => $itemId]);

        $this->postJson('/api/wishlist/items', $this->item('the-sade-sculpted-dress', 'Cobalt', '14', $general))->assertOk();
        $this->assertSame(1, WishlistEvent::where('event_type', 'product_saved')->count());

        $this->patchJson("/api/wishlist/items/{$itemId}", ['notify_price_drop' => true])->assertOk();
        $this->patchJson("/api/wishlist/items/{$itemId}", ['notify_price_drop' => false])->assertOk();
        $this->assertDatabaseHas('wishlist_events', ['event_type' => 'price_alert_enabled']);
        $this->assertDatabaseHas('wishlist_events', ['event_type' => 'price_alert_disabled']);

        $this->postJson('/api/wishlist/merge', [
            'items' => [$this->mergeItem('the-lola-contrast-jumpsuit', 'Pink / Blue', '12')],
        ])->assertOk();
        $this->assertSame(1, WishlistEvent::where('event_type', 'product_saved')->count());
        $this->assertSame(1, WishlistEvent::where('event_type', 'wishlist_migrated')->count());
    }

    public function test_board_move_remove_and_selection_events_preserve_customer_ownership(): void
    {
        $customer = User::factory()->create();
        $general = $this->actingAs($customer)->getJson('/api/wishlist')->json('general_wishlist_id');
        $boardResponse = $this->postJson('/api/wishlist/boards', ['name' => 'December Edit'])->assertCreated();
        $custom = (int) collect($boardResponse->json('wishlists'))->firstWhere('name', 'December Edit')['id'];
        $itemResponse = $this->postJson('/api/wishlist/items', $this->item('the-kemi-column-dress', 'Berry', '14', $general))->assertCreated();
        $itemId = (int) $itemResponse->json('wishlists.0.items.0.id');

        $this->patchJson("/api/wishlist/items/{$itemId}", ['wishlist_id' => $custom])->assertOk();
        $this->patchJson("/api/wishlist/items/{$itemId}", ['size' => '16', 'quantity' => 2])->assertOk();
        $this->deleteJson("/api/wishlist/items/{$itemId}")->assertOk();

        $this->assertDatabaseHas('wishlist_events', ['event_type' => 'board_created', 'user_id' => $customer->id]);
        $this->assertDatabaseHas('wishlist_events', ['event_type' => 'product_moved', 'user_id' => $customer->id]);
        $this->assertDatabaseHas('wishlist_events', ['event_type' => 'selection_updated', 'size' => '16']);
        $this->assertDatabaseHas('wishlist_events', ['event_type' => 'quantity_updated', 'quantity' => 2]);
        $this->assertDatabaseHas('wishlist_events', ['event_type' => 'product_removed', 'wishlist_item_id' => $itemId]);
    }

    public function test_admin_dashboard_search_filters_drilldowns_and_export_are_protected(): void
    {
        [$admin, $customer, $item] = $this->dashboardFixture();
        $ordinary = User::factory()->create();
        Gate::before(fn (User $user, string $ability) => $ability === 'admin' ? $user->is($admin) : null);

        $this->actingAs($ordinary)->get('/admin/wishlists?section=products')->assertForbidden();
        $this->actingAs($ordinary)->get('/admin/wishlists/export/products')->assertForbidden();
        auth()->logout();
        $this->get('/admin/wishlists')->assertRedirect('/login');

        $this->actingAs($admin)->get('/admin/wishlists?section=products&q=Sade&colour=Cobalt&size=14&alert=yes')
            ->assertOk()
            ->assertSee('Product intelligence')
            ->assertSee('The Sade Sculpted Dress')
            ->assertSee('Cobalt');
        $this->actingAs($admin)->get('/admin/wishlists?section=customers&q='.urlencode($customer->email))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee($customer->email);
        $this->actingAs($admin)->get('/admin/wishlists/products/'.$item->product_key)
            ->assertOk()
            ->assertSee('Variant demand')
            ->assertSee('Wishlist customers')
            ->assertSee('href="http://localhost:5173/product/sade-sculpted-dress"', false)
            ->assertDontSee('target="_blank"', false);
        $this->actingAs($admin)->get('/admin/wishlists/customers/'.$customer->id)
            ->assertOk()
            ->assertSee('Read-only customer wishlist profile')
            ->assertSee('General Wishlist')
            ->assertSee('The Sade Sculpted Dress');
        $this->actingAs($admin)->get('/admin/wishlists/export/price-drop')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_dashboard_metrics_keep_unique_customers_save_events_and_current_saves_distinct(): void
    {
        [$admin, $customer, $item] = $this->dashboardFixture();
        WishlistEvent::create([
            'event_type' => 'product_saved', 'user_id' => $customer->id, 'wishlist_item_id' => $item->id,
            'product_key' => $item->product_key, 'variant_key' => $item->variant_key, 'occurred_at' => now(),
        ]);
        WishlistEvent::create([
            'event_type' => 'product_removed', 'user_id' => $customer->id, 'wishlist_item_id' => 999,
            'product_key' => $item->product_key, 'variant_key' => $item->variant_key, 'occurred_at' => now(),
        ]);
        $this->allowAdmin($admin);

        $response = $this->actingAs($admin)->get('/admin/wishlists?range=all');
        $response->assertOk()
            ->assertViewHas('metrics', fn (array $metrics) => $metrics['wishlist_customers'] === 1
                && $metrics['active_saved_products'] === 1
                && $metrics['save_events'] === 1)
            ->assertSee('Current-state figures use active wishlist records')
            ->assertSee('Current saves')
            ->assertSee('Tracked history');
    }

    public function test_dashboard_orders_numeric_and_named_sizes_without_database_specific_casts(): void
    {
        [$admin, $customer, $item] = $this->dashboardFixture();

        foreach (['8', '10', 'XL'] as $index => $size) {
            WishlistItem::create(array_merge($this->itemAttributes($customer, $item->board), [
                'variant_key' => hash('sha256', 'dashboard-size-'.$size),
                'size' => $size,
                'selected_attributes' => ['colour' => 'Cobalt', 'size' => $size],
            ]));
        }

        $this->allowAdmin($admin);

        $this->actingAs($admin)
            ->get('/admin/wishlists')
            ->assertOk()
            ->assertViewHas('available_sizes', fn (Collection $sizes): bool => $sizes->all() === ['8', '10', '14', 'XL']);
    }

    public function test_dashboard_uses_non_overlapping_portraits_and_normalizes_stored_relative_images(): void
    {
        [$admin, $customer, $item] = $this->dashboardFixture();
        WishlistItem::create(array_merge($this->itemAttributes($customer, $item->board), [
            'product_key' => 'the-lola-contrast-jumpsuit',
            'product_code' => 'chiclagos-lola-contrast-jumpsuit',
            'variant_key' => hash('sha256', 'dashboard-affinity-lola'),
            'name' => 'The Lola Contrast Jumpsuit',
            'image' => '/shop-products/lola-front.webp',
        ]));

        $this->allowAdmin($admin);

        $this->actingAs($admin)
            ->get('/admin/wishlists')
            ->assertOk()
            ->assertSee('wi-affinity-thumbs', false)
            ->assertSee('http://localhost:5173/shop-products/sade-front.webp', false)
            ->assertSee('http://localhost:5173/shop-products/lola-front.webp', false)
            ->assertDontSee('margin-left:-8px', false);
    }

    public function test_purchase_conversion_is_same_customer_same_variant_later_and_idempotent(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $board = WishlistBoard::create(['user_id' => $customer->id, 'name' => 'General Wishlist', 'system_key' => 'general']);
        $item = WishlistItem::create(array_merge($this->itemAttributes($customer, $board), [
            'variant_code' => 'chiclagos-sade-sculpted-dress-colour-cobalt',
        ]));
        $order = new FakeWishlistOrder('order-100', [new FakeWishlistOrderProduct(
            'line-1', 'chiclagos-sade-sculpted-dress-colour-cobalt', 'Cobalt', '14', 2, 35700,
        )]);
        $tracker = app(WishlistFunnelTracker::class);

        $tracker->purchased($other, $order);
        $this->assertDatabaseCount('wishlist_events', 0);
        $tracker->purchased($customer, $order);
        $tracker->purchased($customer, $order);

        $this->assertSame(1, WishlistEvent::where('event_type', 'purchased')->count());
        $this->assertDatabaseHas('wishlist_events', [
            'event_type' => 'purchased', 'user_id' => $customer->id, 'order_id' => 'order-100',
            'wishlist_item_id' => $item->id, 'quantity' => 2,
        ]);
    }

    public function test_purchase_attribution_survives_customer_removing_the_saved_item(): void
    {
        $customer = User::factory()->create();
        $board = WishlistBoard::create(['user_id' => $customer->id, 'name' => 'General Wishlist', 'system_key' => 'general']);
        $item = WishlistItem::create(array_merge($this->itemAttributes($customer, $board), [
            'variant_code' => 'chiclagos-sade-sculpted-dress-colour-cobalt',
        ]));
        app(WishlistEventRecorder::class)->item('product_saved', $customer, $item);
        $itemId = $item->id;
        $item->delete();

        $order = new FakeWishlistOrder('order-after-remove', [new FakeWishlistOrderProduct(
            'line-after-remove', 'chiclagos-sade-sculpted-dress-colour-cobalt', 'Cobalt', '14', 1, 35700,
        )]);
        app(WishlistFunnelTracker::class)->purchased($customer, $order);

        $this->assertDatabaseHas('wishlist_events', [
            'event_type' => 'purchased',
            'user_id' => $customer->id,
            'wishlist_item_id' => $itemId,
            'order_id' => 'order-after-remove',
        ]);
    }

    private function dashboardFixture(): array
    {
        $admin = User::factory()->create(['name' => 'Wishlist Admin']);
        $customer = User::factory()->create(['name' => 'Ada Customer', 'email' => 'ada@example.com']);
        $board = WishlistBoard::create(['user_id' => $customer->id, 'name' => 'General Wishlist', 'system_key' => 'general']);
        $item = WishlistItem::create($this->itemAttributes($customer, $board));

        return [$admin, $customer, $item];
    }

    private function allowAdmin(User $admin): void
    {
        Gate::before(fn (User $user, string $ability) => $user->is($admin) && $ability === 'admin' ? true : null);
    }

    private function itemAttributes(User $customer, WishlistBoard $board): array
    {
        return [
            'user_id' => $customer->id, 'wishlist_board_id' => $board->id,
            'product_key' => 'the-sade-sculpted-dress', 'product_code' => 'chiclagos-sade-sculpted-dress',
            'variant_key' => hash('sha256', 'the-sade-sculpted-dress|cobalt|14'),
            'name' => 'The Sade Sculpted Dress', 'category' => 'Dress', 'price' => 35700, 'added_price' => 38000,
            'currency' => 'NGN', 'image' => '/shop-products/sade-front.webp',
            'colours' => [['name' => 'Cobalt', 'value' => '#254a7c', 'images' => ['/shop-products/sade-front.webp']]],
            'sizes' => ['8', '10', '12', '14', '16', '18'], 'colour' => 'Cobalt', 'size' => '14',
            'selected_attributes' => ['colour' => 'Cobalt', 'size' => '14'], 'quantity' => 1,
            'availability' => 'In stock', 'note' => 'Synthetic analytics fixture.', 'notify_price_drop' => true,
            'alert_enabled_at' => now(),
        ];
    }

    private function item(string $productKey, string $colour, string $size, string $wishlistId): array
    {
        return $this->mergeItem($productKey, $colour, $size) + ['wishlist_id' => (int) $wishlistId];
    }

    private function mergeItem(string $productKey, string $colour, string $size): array
    {
        return [
            'product_key' => $productKey, 'name' => ucwords(str_replace('-', ' ', $productKey)), 'category' => 'Dress',
            'price' => 38500, 'added_price' => 38500, 'image' => '/shop-products/test.webp',
            'colours' => [['name' => $colour, 'value' => '#822743', 'images' => ['/shop-products/test.webp']]],
            'sizes' => ['8', '10', '12', '14', '16'], 'colour' => $colour, 'size' => $size, 'quantity' => 1,
            'availability' => 'In stock', 'note' => 'A genuine customer selection.', 'notify_price_drop' => false,
        ];
    }
}

class FakeWishlistOrder
{
    public function __construct(private readonly string $id, private readonly array $products) {}
    public function getId(): string { return $this->id; }
    public function getProducts(): array { return $this->products; }
}

class FakeWishlistOrderProduct
{
    public function __construct(
        private readonly string $id,
        private readonly string $code,
        private readonly string $colour,
        private readonly string $size,
        private readonly int $quantity,
        private readonly float $price,
    ) {}
    public function getId(): string { return $this->id; }
    public function getProductCode(): string { return $this->code; }
    public function getParentProductId(): null { return null; }
    public function getQuantity(): int { return $this->quantity; }
    public function getPrice(): FakeWishlistPrice { return new FakeWishlistPrice($this->price); }
    public function getAttributeItems(): Collection { return collect([new FakeWishlistAttribute('variant', 'color', $this->colour, $this->colour), new FakeWishlistAttribute('custom', 'size', 'Size', $this->size)]); }
}

class FakeWishlistAttribute
{
    public function __construct(private readonly string $type, private readonly string $code, private readonly string $name, private readonly string $value) {}
    public function getType(): string { return $this->type; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getValue(): string { return $this->value; }
}

class FakeWishlistPrice
{
    public function __construct(private readonly float $value) {}
    public function getValue(): float { return $this->value; }
    public function getCurrencyId(): string { return 'NGN'; }
}
