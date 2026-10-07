<?php

namespace Tests\Feature;

use App\Models\PaystackPayment;
use App\Models\User;
use App\Services\CompletedBasketFinalizer;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaystackPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PaystackCartFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_paid_payment_records_cart_finalization_once_even_when_verification_is_retried(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $payment = $this->payment($user, [
            'reference' => 'chic_pay_already_paid',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $first = app(PaystackPaymentService::class)->verifyForUser($user, $payment->reference);
        $recordedAt = $payment->fresh()->cart_finalized_at;
        $second = app(PaystackPaymentService::class)->verifyForUser($user, $payment->reference);

        $this->assertTrue($first['cart_finalized']);
        $this->assertSame('not_linked', $first['cart']['status']);
        $this->assertNotNull($recordedAt);
        $this->assertSame($recordedAt->toISOString(), $payment->fresh()->cart_finalized_at->toISOString());
        $this->assertSame($first['cart_finalized_at'], $second['cart_finalized_at']);
    }

    public function test_pending_or_failed_verification_does_not_finalize_the_cart(): void
    {
        $user = User::factory()->create(['email' => 'pending@example.com', 'email_verified_at' => now()]);
        $payment = $this->payment($user, [
            'reference' => 'chic_pay_pending',
            'status' => 'pending',
            'source_basket_id' => 'basket-that-must-not-be-cleared',
            'source_basket_suffix' => '-default-en-NGN-customer-'.$user->id,
        ]);

        $this->mock(PaymentGateway::class, function (MockInterface $mock) use ($payment, $user): void {
            $mock->shouldReceive('verify')->once()->with($payment->reference)->andReturn([
                'reference' => $payment->reference,
                'amount' => $payment->expected_amount,
                'currency' => 'NGN',
                'domain' => 'test',
                'status' => 'pending',
                'metadata' => [
                    'purpose' => 'order',
                    'order_id' => $payment->order_id,
                    'customer_id' => (string) $user->id,
                    'payment_reference' => $payment->reference,
                ],
                'customer' => ['email' => $user->email],
            ]);
        });

        $result = app(PaystackPaymentService::class)->verifyForUser($user, $payment->reference);

        $this->assertSame('pending', $result['status']);
        $this->assertFalse($result['cart_finalized']);
        $this->assertNull($payment->fresh()->cart_finalized_at);
    }

    public function test_paid_snapshot_subtraction_preserves_new_products_variants_and_paid_versions_of_gifts(): void
    {
        $red = [new FinalizerAttribute('variant', 'color', 'Red'), new FinalizerAttribute('custom', 'size', '10')];
        $blue = [new FinalizerAttribute('variant', 'color', 'Blue'), new FinalizerAttribute('custom', 'size', '10')];
        $basket = new FinalizerOrder([
            0 => new FinalizerLine('dress-red', 'parent-dress', $red, 3, 100),
            1 => new FinalizerLine('dress-blue', 'parent-dress', $blue, 1, 100),
            2 => new FinalizerLine('new-after-checkout', 'parent-new', $red, 1, 80),
            3 => new FinalizerLine('gift-variant', 'parent-gift', $blue, 1, 50),
            4 => new FinalizerLine('gift-variant', 'parent-gift', $blue, 1, 0),
        ]);
        $paidOrder = new FinalizerOrder([
            0 => new FinalizerLine('dress-red', 'parent-dress', $red, 2, 100),
            1 => new FinalizerLine('gift-variant', 'parent-gift', $blue, 1, 0),
        ]);

        $method = new \ReflectionMethod(CompletedBasketFinalizer::class, 'subtractOrderSnapshot');
        $method->invoke(app(CompletedBasketFinalizer::class), $basket, $paidOrder);
        $remaining = $basket->getProducts()->toArray();

        $this->assertSame(1, $remaining[0]->getQuantity());
        $this->assertSame(1, $remaining[1]->getQuantity());
        $this->assertSame('new-after-checkout', $remaining[2]->getProductCode());
        $this->assertSame(50.0, $remaining[3]->getPrice()->getValue());
        $this->assertArrayNotHasKey(4, $remaining);
    }

    private function payment(User $user, array $overrides): PaystackPayment
    {
        return PaystackPayment::create(array_merge([
            'user_id' => $user->id,
            'order_id' => '1001',
            'reference' => 'chic_pay_reference',
            'expected_amount' => 1250000,
            'wallet_amount' => 0,
            'currency' => 'NGN',
            'environment' => 'test',
            'status' => 'pending',
            'metadata' => ['email' => $user->email],
        ], $overrides));
    }
}

class FinalizerOrder
{
    public function __construct(private array $products) {}

    public function getProducts(): FinalizerProducts
    {
        return new FinalizerProducts($this->products);
    }

    public function deleteProduct(int $position): self
    {
        unset($this->products[$position]);

        return $this;
    }

    public function addProduct(FinalizerLine $item, ?int $position = null): self
    {
        $position === null ? $this->products[] = $item : $this->products[$position] = $item;

        return $this;
    }
}

class FinalizerProducts
{
    public function __construct(private readonly array $products) {}

    public function toArray(): array
    {
        return $this->products;
    }
}

class FinalizerLine
{
    public function __construct(
        private readonly string $productCode,
        private readonly string $parentProductId,
        private readonly array $attributes,
        private int $quantity,
        private readonly float $price,
    ) {}

    public function getProductCode(): string
    {
        return $this->productCode;
    }

    public function getParentProductId(): string
    {
        return $this->parentProductId;
    }

    public function getAttributeItems(): array
    {
        return $this->attributes;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getPrice(): FinalizerPrice
    {
        return new FinalizerPrice($this->price);
    }
}

class FinalizerAttribute
{
    public function __construct(
        private readonly string $type,
        private readonly string $code,
        private readonly string $value,
    ) {}

    public function getType(): string
    {
        return $this->type;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

class FinalizerPrice
{
    public function __construct(private readonly float $value) {}

    public function getValue(): float
    {
        return $this->value;
    }
}
