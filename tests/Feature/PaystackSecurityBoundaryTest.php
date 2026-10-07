<?php

namespace Tests\Feature;

use App\Models\PaystackPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaystackSecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_requires_an_authenticated_verified_customer(): void
    {
        $this->postJson('/api/checkout/order', [])->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->postJson('/api/checkout/order', [])->assertForbidden();
    }

    public function test_customer_cannot_read_another_customers_payment(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        PaystackPayment::create([
            'user_id' => $owner->id,
            'order_id' => '1001',
            'reference' => 'chic_pay_private',
            'expected_amount' => 1250000,
            'currency' => 'NGN',
            'environment' => 'test',
            'status' => 'pending',
        ]);

        $this->actingAs($other)
            ->getJson('/api/paystack/payments/chic_pay_private')
            ->assertNotFound();
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        config()->set('services.paystack.secret', 'sk_test_webhook_secret');

        $this->postJson('/api/paystack/webhook', [
            'event' => 'charge.success',
            'data' => ['reference' => 'chic_pay_unknown'],
        ], ['x-paystack-signature' => 'invalid'])->assertUnauthorized();
    }

    public function test_validly_signed_unknown_reference_is_ignored_without_fulfilling_anything(): void
    {
        $secret = 'sk_test_webhook_secret';
        config()->set('services.paystack.secret', $secret);
        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'chic_pay_unknown']];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/api/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $json, $secret),
        ], $json)->assertOk()->assertJson(['received' => true, 'ignored' => true]);

        $this->assertDatabaseCount('paystack_payments', 0);
    }
}
