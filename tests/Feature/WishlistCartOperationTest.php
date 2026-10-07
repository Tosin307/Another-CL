<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WishlistCartOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_operation_is_replayed_without_a_duplicate_basket_mutation(): void
    {
        $customer = User::factory()->create();
        $operation = (string) Str::uuid();
        $result = [
            'message' => '1 item added to your bag.',
            'success_count' => 1,
            'failure_count' => 0,
            'results' => [['wishlist_item_id' => 42, 'success' => true, 'message' => 'Added.']],
            'cart' => ['count' => 2, 'subtotal' => 75000, 'currency' => 'NGN', 'items' => []],
        ];
        DB::table('wishlist_cart_operations')->insert([
            'user_id' => $customer->id,
            'operation_key' => $operation,
            'source' => 'individual',
            'status' => 'completed',
            'result' => json_encode($result),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($customer)->postJson('/api/cart/wishlist-items', [
            'operation_id' => $operation,
            'source' => 'individual',
            'items' => [['wishlist_item_id' => 42, 'wishlist_board_id' => 7, 'quantity' => 1]],
        ])->assertOk()->assertExactJson($result);

        $this->assertDatabaseCount('wishlist_cart_operations', 1);
    }

    public function test_empty_invalid_or_unauthenticated_bulk_requests_never_start_an_operation(): void
    {
        $payload = ['operation_id' => (string) Str::uuid(), 'source' => 'bulk', 'items' => []];
        $this->postJson('/api/cart/wishlist-items', $payload)->assertUnauthorized();

        $customer = User::factory()->create();
        $this->actingAs($customer)->postJson('/api/cart/wishlist-items', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/cart/wishlist-items', ['operation_id' => 'not-a-uuid', 'source' => 'bulk', 'items' => [['wishlist_item_id' => 1, 'wishlist_board_id' => 1, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('operation_id');

        $this->assertDatabaseCount('wishlist_cart_operations', 0);
    }
}
