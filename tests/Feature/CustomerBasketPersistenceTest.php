<?php

namespace Tests\Feature;

use App\Services\CustomerBasketLocator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerBasketPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_basket_reference_survives_new_sessions_without_cross_customer_or_guest_leakage(): void
    {
        Schema::create('mshop_basket', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('customerid')->default('');
            $table->longText('content')->default('');
            $table->timestamp('mtime');
            $table->timestamp('ctime');
        });

        $suffix = '-default-en-NGN-customer-41';
        $cart = [
            'items' => [
                ['product' => 'chiclagos-sade-sculpted-dress', 'colour' => 'Cobalt', 'size' => '10', 'quantity' => 2, 'unit_price' => 35700],
                ['product' => 'chiclagos-sade-sculpted-dress', 'colour' => 'Burgundy', 'size' => '12', 'quantity' => 1, 'unit_price' => 35700],
            ],
            'quantity' => 3,
            'subtotal' => 107100,
        ];

        $this->insertBasket('old-token'.$suffix, '41', $cart, '2026-08-30 17:00:00');
        $this->insertBasket('other-token-default-en-NGN-customer-42', '42', [
            'items' => [['product' => 'chiclagos-lola-contrast-jumpsuit', 'colour' => 'Midnight', 'size' => '14', 'quantity' => 1]],
            'quantity' => 1,
        ], '2026-08-30 17:05:00');
        $this->insertBasket('guest-token-default-en-NGN-guest', '', [
            'items' => [['product' => 'chiclagos-imani-wrap-dress', 'colour' => 'Coral', 'size' => '12', 'quantity' => 1]],
            'quantity' => 1,
        ], '2026-08-30 17:10:00');

        $locator = app(CustomerBasketLocator::class);
        $newSessionId = 'new-token'.$suffix;

        $this->assertFalse($locator->exists($newSessionId));
        $restoredId = $locator->latestId('41', $suffix);
        $this->assertSame('old-token'.$suffix, $restoredId);
        $this->assertSame($cart, json_decode(DB::table('mshop_basket')->where('id', $restoredId)->value('content'), true));
        $this->assertNull($locator->latestId('42', $suffix));
        $this->assertNull($locator->latestId('', $suffix));

        // Rebinding creates the current secure-session reference without
        // changing product variants, quantities or totals.
        $this->insertBasket($newSessionId, '41', $cart, '2026-08-30 17:15:00');
        $this->assertTrue($locator->exists($newSessionId));
        $this->assertSame($newSessionId, $locator->latestId('41', $suffix));
        $this->assertSame([$newSessionId, 'old-token'.$suffix], $locator->matchingIds('41', $suffix));
        $this->assertSame([], $locator->matchingIds('', $suffix));

        // An explicit clear or completed checkout is represented by the latest
        // empty customer basket and must prevent an older cart resurfacing.
        $emptyCart = ['items' => [], 'quantity' => 0, 'subtotal' => 0];
        $clearedId = 'cleared-token'.$suffix;
        $this->insertBasket($clearedId, '41', $emptyCart, '2026-08-30 17:20:00');
        $this->assertSame($clearedId, $locator->latestId('41', $suffix));
        $this->assertSame($emptyCart, json_decode(DB::table('mshop_basket')->where('id', $clearedId)->value('content'), true));
    }

    private function insertBasket(string $id, string $customerId, array $content, string $timestamp): void
    {
        DB::table('mshop_basket')->insert([
            'id' => $id,
            'customerid' => $customerId,
            'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'mtime' => $timestamp,
            'ctime' => $timestamp,
        ]);
    }
}
