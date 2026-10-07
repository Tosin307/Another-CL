<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderConfirmationMailTest extends TestCase
{
    public function test_customer_email_contains_paid_order_lines_delivery_and_secure_order_link(): void
    {
        $html = view('emails.order-confirmation', [
            'internal' => false,
            'details' => $this->details(),
        ])->render();

        $this->assertStringContainsString('CHIC', $html);
        $this->assertStringContainsString('LAGOS', $html);
        $this->assertStringContainsString('Sade Sculpted Dress', $html);
        $this->assertStringContainsString('Pink', $html);
        $this->assertStringContainsString('Size 12', $html);
        $this->assertStringContainsString('Qty 1', $html);
        $this->assertStringContainsString('44,500.00', $html);
        $this->assertStringContainsString('4,500.00', $html);
        $this->assertStringContainsString('49,000.00', $html);
        $this->assertStringContainsString('https://api.chiclagos.com/aimeos/order/sade.webp', $html);
        $this->assertStringContainsString('https://www.chiclagos.com/my-orders', $html);
        $this->assertStringNotContainsString('after payment review', $html);
    }

    public function test_internal_email_links_to_the_private_aimeos_admin(): void
    {
        $html = view('emails.order-confirmation', [
            'internal' => true,
            'details' => $this->details(),
        ])->render();

        $this->assertStringContainsString('New paid order', $html);
        $this->assertStringContainsString('Open Aimeos orders', $html);
        $this->assertStringContainsString('https://api.chiclagos.com/admin', $html);
        $this->assertStringContainsString('chic_pay_test_reference', $html);
    }

    private function details(): array
    {
        return [
            'order_number' => '1',
            'customer_name' => 'Test Customer',
            'email' => 'customer@example.com',
            'shipping_method' => 'DHL Express',
            'delivery_address' => 'Ajah, Lagos, NG',
            'subtotal' => '44,500.00',
            'delivery' => '4,500.00',
            'total' => '49,000.00',
            'currency' => 'NGN',
            'payment_reference' => 'chic_pay_test_reference',
            'paid_at' => '1 Sep 2026, 1:53 AM',
            'order_note' => 'Please pack carefully.',
            'view_order_url' => 'https://www.chiclagos.com/my-orders',
            'admin_order_url' => 'https://api.chiclagos.com/admin',
            'items' => [[
                'name' => 'Sade Sculpted Dress',
                'colour' => 'Pink',
                'size' => '12',
                'quantity' => 1,
                'unit_price' => '44,500.00',
                'line_total' => '44,500.00',
                'is_gift' => false,
                'image' => 'https://api.chiclagos.com/aimeos/order/sade.webp',
            ]],
        ];
    }
}
