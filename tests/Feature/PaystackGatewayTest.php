<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentException;
use App\Services\Payments\PaystackGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.paystack.secret', 'sk_test_example');
        config()->set('services.paystack.mode', 'test');
        config()->set('app.frontend_url', 'http://localhost:5173');
        config()->set('services.paystack.callback_url', 'http://localhost:5173/payment/callback');
    }

    public function test_it_initializes_only_through_the_server_and_accepts_the_paystack_hosted_url(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/trusted-code',
                    'access_code' => 'trusted-code',
                    'reference' => 'chic_pay_reference',
                ],
            ]),
        ]);

        $result = app(PaystackGateway::class)->initialize([
            'email' => 'buyer@example.com',
            'amount' => '1250000',
            'reference' => 'chic_pay_reference',
            'currency' => 'NGN',
            'callback_url' => 'http://localhost:5173/payment/callback',
        ]);

        $this->assertSame('https://checkout.paystack.com/trusted-code', $result['authorization_url']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request->hasHeader('Authorization', 'Bearer sk_test_example')
            && $request['amount'] === '1250000'
            && $request['reference'] === 'chic_pay_reference');
    }

    public function test_it_rejects_an_untrusted_checkout_redirect(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://attacker.example/collect-card',
                    'access_code' => 'code',
                    'reference' => 'chic_pay_reference',
                ],
            ]),
        ]);

        $this->expectException(PaymentException::class);
        app(PaystackGateway::class)->initialize([
            'email' => 'buyer@example.com',
            'amount' => '1250000',
            'reference' => 'chic_pay_reference',
            'currency' => 'NGN',
            'callback_url' => 'http://localhost:5173/payment/callback',
        ]);
    }

    public function test_it_refuses_a_live_key_while_test_mode_is_locked(): void
    {
        config()->set('services.paystack.secret', 'sk_live_wrong_environment');

        $this->expectException(PaymentException::class);
        app(PaystackGateway::class)->verify('chic_pay_reference');
        Http::assertNothingSent();
    }
}
