<?php

namespace Tests\Feature;

use App\Mail\ChicLagosWishlistPriceDrop;
use App\Jobs\SendWishlistPriceDropEmail;
use App\Models\CustomerAlertSubscription;
use App\Models\User;
use App\Models\WishlistBoard;
use App\Models\WishlistItem;
use App\Models\WishlistPriceDropNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class WishlistPriceAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_is_owned_variant_specific_and_duplicate_safe(): void
    {
        [$customer, $item] = $this->fixture();
        $other = User::factory()->create();

        $this->actingAs($other)->postJson("/api/wishlist/items/{$item->id}/price-alert")->assertNotFound();
        $this->actingAs($customer)->postJson("/api/wishlist/items/{$item->id}/price-alert")
            ->assertCreated()->assertJsonPath('active', true)->assertJsonPath('subscription_price', 35000);
        $this->postJson("/api/wishlist/items/{$item->id}/price-alert")->assertCreated();

        $this->assertSame(1, CustomerAlertSubscription::where('kind', 'wishlist_price_drop')->where('status', 'active')->count());
        $this->assertDatabaseHas('wishlist_items', ['id' => $item->id, 'notify_price_drop' => true]);
        $this->assertDatabaseHas('customer_alert_subscriptions', [
            'wishlist_item_id' => $item->id,
            'product_key' => $item->product_key,
            'variant_key' => $item->variant_key,
            'colour' => 'Black',
            'size' => '14',
        ]);
    }

    public function test_checker_sends_once_per_genuine_drop_and_unsubscribe_stops_future_mail(): void
    {
        Mail::fake();
        [$customer, $item] = $this->fixture();
        $this->actingAs($customer)->postJson("/api/wishlist/items/{$item->id}/price-alert")->assertCreated();

        $this->artisan('wishlist:check-price-drops', ['--sync' => true])->assertSuccessful();
        Mail::assertNothingSent();

        $item->update(['price' => 30000]);
        $this->artisan('wishlist:check-price-drops', ['--sync' => true])->assertSuccessful();
        Mail::assertSent(ChicLagosWishlistPriceDrop::class, 1);
        $this->assertDatabaseHas('wishlist_price_drop_notifications', ['previous_price' => 35000, 'new_price' => 30000, 'status' => 'sent']);

        $this->artisan('wishlist:check-price-drops', ['--sync' => true])->assertSuccessful();
        Mail::assertSent(ChicLagosWishlistPriceDrop::class, 1);

        $item->update(['price' => 40000]);
        $this->artisan('wishlist:check-price-drops', ['--sync' => true])->assertSuccessful();
        Mail::assertSent(ChicLagosWishlistPriceDrop::class, 1);

        $item->update(['price' => 38000]);
        $this->artisan('wishlist:check-price-drops', ['--sync' => true])->assertSuccessful();
        Mail::assertSent(ChicLagosWishlistPriceDrop::class, 1);

        $item->update(['price' => 28000]);
        $this->artisan('wishlist:check-price-drops', ['--sync' => true])->assertSuccessful();
        Mail::assertSent(ChicLagosWishlistPriceDrop::class, 2);
        $this->assertSame(2, WishlistPriceDropNotification::where('status', 'sent')->count());

        $subscription = CustomerAlertSubscription::where('status', 'active')->firstOrFail();
        $this->get('/wishlist/price-alerts/unsubscribe/'.$subscription->unsubscribe_token)
            ->assertRedirect(config('app.frontend_url').'/wishlist?price_alert=off');
        $item->update(['price' => 25000]);
        $this->artisan('wishlist:check-price-drops', ['--sync' => true])->assertSuccessful();
        Mail::assertSent(ChicLagosWishlistPriceDrop::class, 2);
        $this->assertDatabaseHas('customer_alert_subscriptions', ['id' => $subscription->id, 'status' => 'unsubscribed', 'active_key' => null]);
        $this->assertDatabaseHas('wishlist_items', ['id' => $item->id, 'notify_price_drop' => false]);
    }

    public function test_price_drop_email_contains_selection_savings_storefront_and_single_alert_unsubscribe(): void
    {
        [$customer, $item] = $this->fixture();
        $this->actingAs($customer)->postJson("/api/wishlist/items/{$item->id}/price-alert")->assertCreated();
        $subscription = CustomerAlertSubscription::firstOrFail();
        $notification = WishlistPriceDropNotification::create([
            'customer_alert_subscription_id' => $subscription->id,
            'event_key' => hash('sha256', 'email-render'),
            'previous_price' => 35000,
            'new_price' => 29500,
            'currency' => 'NGN',
            'status' => 'detected',
            'detected_at' => now(),
        ]);

        $html = (new ChicLagosWishlistPriceDrop($notification))->render();

        $this->assertStringContainsString('The Lola Contrast Jumpsuit', $html);
        $this->assertStringContainsString('Black · UK 14', $html);
        $this->assertStringContainsString('₦35,000', $html);
        $this->assertStringContainsString('₦29,500', $html);
        $this->assertStringContainsString('₦5,500', $html);
        $this->assertStringContainsString('16%', $html);
        $this->assertStringContainsString(config('app.frontend_url').'/product/lola-contrast-jumpsuit', $html);
        $this->assertStringContainsString('/wishlist/price-alerts/unsubscribe/'.$subscription->unsubscribe_token, $html);
    }

    public function test_existing_admin_design_exposes_real_alert_and_notification_state_at_singular_url(): void
    {
        [$customer, $item] = $this->fixture();
        $this->actingAs($customer)->postJson("/api/wishlist/items/{$item->id}/price-alert")->assertCreated();
        $subscription = CustomerAlertSubscription::firstOrFail();
        WishlistPriceDropNotification::create([
            'customer_alert_subscription_id' => $subscription->id,
            'event_key' => hash('sha256', 'admin-alert'),
            'previous_price' => 35000,
            'new_price' => 30000,
            'currency' => 'NGN',
            'status' => 'sent',
            'detected_at' => now(),
            'queued_at' => now(),
            'sent_at' => now(),
        ]);
        $admin = User::factory()->create();
        Gate::before(fn (User $user, string $ability) => $user->is($admin) && $ability === 'admin' ? true : null);

        $this->actingAs($admin)->get('/admin/wishlist?section=price-drop')
            ->assertOk()
            ->assertSee('Price-drop intelligence')
            ->assertSee('The Lola Contrast Jumpsuit')
            ->assertSee('ada@example.com')
            ->assertSee('Notification status')
            ->assertSee('Transport state only');
        $this->from('/admin/wishlist?section=price-drop')
            ->patch('/admin/wishlists/price-alerts/'.$subscription->id.'/deactivate')
            ->assertRedirect('/admin/wishlist?section=price-drop');
        $this->assertDatabaseHas('customer_alert_subscriptions', ['id' => $subscription->id, 'status' => 'unsubscribed', 'active_key' => null]);
        $this->assertDatabaseHas('wishlist_items', ['id' => $item->id, 'notify_price_drop' => false]);
    }

    public function test_terminal_mail_failure_is_recorded_without_claiming_delivery(): void
    {
        [$customer, $item] = $this->fixture();
        $this->actingAs($customer)->postJson("/api/wishlist/items/{$item->id}/price-alert")->assertCreated();
        $subscription = CustomerAlertSubscription::firstOrFail();
        $notification = WishlistPriceDropNotification::create([
            'customer_alert_subscription_id' => $subscription->id,
            'event_key' => hash('sha256', 'failed-email'),
            'previous_price' => 35000,
            'new_price' => 30000,
            'currency' => 'NGN',
            'status' => 'queued',
            'detected_at' => now(),
            'queued_at' => now(),
        ]);

        (new SendWishlistPriceDropEmail($notification->id))->failed(new \RuntimeException('Mail transport unavailable.'));

        $this->assertDatabaseHas('wishlist_price_drop_notifications', [
            'id' => $notification->id,
            'status' => 'failed',
            'failure_reason' => 'Mail transport unavailable.',
        ]);
        $this->assertDatabaseHas('wishlist_events', ['event_type' => 'price_drop_email_failed']);
    }

    private function fixture(): array
    {
        $customer = User::factory()->create(['name' => 'Ada Customer', 'email' => 'ada@example.com']);
        $board = WishlistBoard::create(['user_id' => $customer->id, 'name' => 'General Wishlist', 'system_key' => 'general']);
        $item = WishlistItem::create([
            'user_id' => $customer->id,
            'wishlist_board_id' => $board->id,
            'product_key' => 'the-lola-contrast-jumpsuit',
            'product_code' => null,
            'variant_key' => hash('sha256', 'the-lola-contrast-jumpsuit|black|14'),
            'name' => 'The Lola Contrast Jumpsuit',
            'category' => 'Jumpsuit',
            'price' => 35000,
            'added_price' => 35000,
            'currency' => 'NGN',
            'image' => '/shop-products/lola-front.webp',
            'colours' => [['name' => 'Black', 'value' => '#111111', 'images' => ['/shop-products/lola-front.webp']]],
            'sizes' => ['10', '12', '14', '16'],
            'colour' => 'Black',
            'size' => '14',
            'selected_attributes' => ['colour' => 'Black', 'size' => '14'],
            'quantity' => 1,
            'availability' => 'In stock',
            'notify_price_drop' => false,
        ]);

        return [$customer, $item];
    }
}
