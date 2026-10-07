<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentException;
use App\Services\Payments\PaystackVerificationValidator;
use Tests\TestCase;

class PaystackVerificationValidatorTest extends TestCase
{
    public function test_every_bound_payment_identity_field_must_match(): void
    {
        $verified = [
            'reference' => 'chic_pay_reference',
            'amount' => 1250000,
            'currency' => 'NGN',
            'domain' => 'test',
            'status' => 'success',
            'metadata' => [
                'purpose' => 'order',
                'order_id' => '1001',
                'customer_id' => '42',
                'payment_reference' => 'chic_pay_reference',
            ],
            'customer' => ['email' => 'buyer@example.com'],
        ];
        $expected = [
            'reference' => 'chic_pay_reference',
            'amount' => 1250000,
            'currency' => 'NGN',
            'environment' => 'test',
            'purpose' => 'order',
            'resource_key' => 'order_id',
            'resource_id' => '1001',
            'customer_id' => '42',
            'email' => 'buyer@example.com',
        ];
        $validator = app(PaystackVerificationValidator::class);
        $validator->assertMatches($verified, $expected, 'Chic Lagos order');
        $this->addToAssertionCount(1);

        $mutations = [
            fn (array $value) => array_replace($value, ['reference' => 'other']),
            fn (array $value) => array_replace($value, ['amount' => 1249999]),
            fn (array $value) => array_replace($value, ['currency' => 'USD']),
            fn (array $value) => array_replace($value, ['domain' => 'live']),
            fn (array $value) => array_replace($value, ['metadata' => array_replace($value['metadata'], ['purpose' => 'wallet_top_up'])]),
            fn (array $value) => array_replace($value, ['metadata' => array_replace($value['metadata'], ['order_id' => '1002'])]),
            fn (array $value) => array_replace($value, ['metadata' => array_replace($value['metadata'], ['customer_id' => '43'])]),
            fn (array $value) => array_replace($value, ['metadata' => array_replace($value['metadata'], ['payment_reference' => 'other'])]),
            fn (array $value) => array_replace($value, ['customer' => ['email' => 'other@example.com']]),
        ];

        foreach ($mutations as $mutate) {
            try {
                $validator->assertMatches($mutate($verified), $expected, 'Chic Lagos order');
                $this->fail('A mismatched Paystack field was accepted.');
            } catch (PaymentException $exception) {
                $this->assertSame(409, $exception->status);
            }
        }
    }
}
