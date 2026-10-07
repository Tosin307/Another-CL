<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentException;
use App\Services\ShippingQuoteService;
use Tests\TestCase;

class ShippingQuoteServiceTest extends TestCase
{
    public function test_server_recalculates_route_fee_and_discount(): void
    {
        $quote = app(ShippingQuoteService::class)->quote('DHL Express', 'NG', 'Lagos', 'Ajah', 100000);

        $this->assertSame(4500.0, $quote['before_discount']);
        $this->assertSame(540.0, $quote['discount']);
        $this->assertSame(3960.0, $quote['fee']);
    }

    public function test_customer_cannot_claim_free_shipping_below_the_threshold(): void
    {
        $this->expectException(PaymentException::class);
        app(ShippingQuoteService::class)->quote('Free delivery', 'NG', 'Lagos', 'Ajah', 149999);
    }

    public function test_server_allows_free_shipping_only_after_the_threshold(): void
    {
        $quote = app(ShippingQuoteService::class)->quote('Free delivery', 'NG', 'Lagos', 'Ajah', 150000);

        $this->assertTrue($quote['free']);
        $this->assertSame(0, $quote['fee']);
    }
}
