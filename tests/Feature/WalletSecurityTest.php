<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletReservation;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentGateway;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class WalletSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_routes_require_a_verified_customer_and_keep_balances_isolated(): void
    {
        $this->getJson('/api/wallet')->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->getJson('/api/wallet')->assertForbidden();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        Wallet::create(['user_id' => $owner->id, 'currency' => 'NGN', 'balance' => 42000]);

        $this->actingAs($owner)->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.balance', 42000);
        $this->actingAs($other)->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.balance', 0);
    }

    public function test_top_up_bonus_is_capped_and_a_verified_top_up_credits_principal_and_store_only_bonus_once(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'NGN']);
        $topUp = WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'provider' => 'paystack',
            'reference' => 'chic_wallet_capped_bonus',
            'amount' => 10000000,
            'bonus_amount' => 15000,
            'credit_amount' => 10015000,
            'status' => 'pending',
        ]);

        $preview = app(WalletService::class)->topUpPreview('10000000.01');
        $this->assertSame(15000.0, $preview['bonus_amount']);
        $this->assertSame(10015000.01, $preview['credit_amount']);
        $this->assertNull($preview['maximum_amount']);
        $this->assertTrue($preview['is_allowed']);

        app(WalletService::class)->completeTopUp($topUp->reference, 'paystack-transaction-1');
        app(WalletService::class)->completeTopUp($topUp->reference, 'paystack-transaction-1');

        $wallet->refresh();
        $this->assertSame('10015000.00', $wallet->balance);
        $this->assertSame('15000.00', $wallet->store_credit_balance);
        $this->assertSame(1, WalletTransaction::where('reference', $topUp->reference)->count());
    }

    public function test_large_top_ups_have_no_business_ceiling_but_amount_format_and_minimum_are_enforced(): void
    {
        config()->set('services.paystack.secret', null);
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'NGN']);
        WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'provider' => 'paystack',
            'reference' => 'chic_wallet_existing_daily_amount',
            'amount' => 900000,
            'bonus_amount' => 0,
            'credit_amount' => 900000,
            'status' => 'paid',
        ]);

        $this->actingAs($user)->postJson('/api/wallet/topups', ['amount' => 10000000])
            ->assertCreated()
            ->assertJsonPath('top_up.amount', 10000000)
            ->assertJsonPath('top_up.bonus_amount', 15000)
            ->assertJsonPath('top_up.credit_amount', 10015000)
            ->assertJsonPath('top_up.maximum_amount', null);

        $this->postJson('/api/wallet/topups', ['amount' => '2999.99'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The minimum wallet top-up is ₦3,000.');
        $this->postJson('/api/wallet/topups', ['amount' => '10000.001'])->assertUnprocessable();
        $this->postJson('/api/wallet/topups', ['amount' => '10000000000000'])->assertUnprocessable();

        $this->assertDatabaseHas('wallet_top_ups', [
            'user_id' => $user->id,
            'amount' => 10000000,
            'bonus_amount' => 15000,
            'credit_amount' => 10015000,
            'status' => 'pending',
        ]);
    }

    public function test_wallet_webhook_requires_an_exact_paystack_match_and_credits_a_large_top_up_only_once(): void
    {
        $secret = 'sk_test_wallet_webhook';
        config()->set('services.paystack.secret', $secret);
        config()->set('services.paystack.mode', 'test');
        $user = User::factory()->create(['email' => 'large-wallet@example.com']);
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'NGN']);
        $topUp = WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'provider' => 'paystack',
            'reference' => 'chic_wallet_large_verified',
            'amount' => '10000000.01',
            'bonus_amount' => '15000.00',
            'credit_amount' => '10015000.01',
            'status' => 'pending',
        ]);

        $this->mock(PaymentGateway::class, function (MockInterface $mock) use ($topUp, $user): void {
            $mock->shouldReceive('verify')->once()->with($topUp->reference)->andReturn([
                'id' => 987654,
                'reference' => $topUp->reference,
                'amount' => 1000000001,
                'currency' => 'NGN',
                'domain' => 'test',
                'status' => 'success',
                'channel' => 'bank_transfer',
                'metadata' => [
                    'purpose' => 'wallet_top_up',
                    'wallet_top_up_id' => (string) $topUp->id,
                    'customer_id' => (string) $user->id,
                    'payment_reference' => $topUp->reference,
                ],
                'customer' => ['email' => $user->email],
            ]);
        });

        $payload = ['event' => 'charge.success', 'data' => ['reference' => $topUp->reference]];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $json, $secret),
        ];

        $this->call('POST', '/api/wallet/paystack/webhook', [], [], [], $server, $json)->assertOk();
        $this->call('POST', '/api/wallet/paystack/webhook', [], [], [], $server, $json)->assertOk();

        $this->assertSame('10015000.01', $wallet->fresh()->balance);
        $this->assertSame('15000.00', $wallet->fresh()->store_credit_balance);
        $this->assertSame(1, WalletTransaction::where('reference', $topUp->reference)->count());
    }

    public function test_wallet_webhook_rejects_a_mismatched_amount_without_crediting_the_wallet(): void
    {
        $secret = 'sk_test_wallet_mismatch';
        config()->set('services.paystack.secret', $secret);
        config()->set('services.paystack.mode', 'test');
        $user = User::factory()->create(['email' => 'wallet-mismatch@example.com']);
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'NGN']);
        $topUp = WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'provider' => 'paystack',
            'reference' => 'chic_wallet_amount_mismatch',
            'amount' => '5000000.00',
            'bonus_amount' => '15000.00',
            'credit_amount' => '5015000.00',
            'status' => 'pending',
        ]);

        $this->mock(PaymentGateway::class, function (MockInterface $mock) use ($topUp, $user): void {
            $mock->shouldReceive('verify')->once()->with($topUp->reference)->andReturn([
                'reference' => $topUp->reference,
                'amount' => 499999999,
                'currency' => 'NGN',
                'domain' => 'test',
                'status' => 'success',
                'metadata' => [
                    'purpose' => 'wallet_top_up',
                    'wallet_top_up_id' => (string) $topUp->id,
                    'customer_id' => (string) $user->id,
                    'payment_reference' => $topUp->reference,
                ],
                'customer' => ['email' => $user->email],
            ]);
        });

        $payload = ['event' => 'charge.success', 'data' => ['reference' => $topUp->reference]];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/api/wallet/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $json, $secret),
        ], $json)->assertUnprocessable();

        $this->assertSame('0.00', $wallet->fresh()->balance);
        $this->assertSame('pending', $topUp->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_high_value_cart_reservation_and_consumption_keep_every_kobo_exact(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'balance' => '10000000.01',
            'store_credit_balance' => '15000.00',
        ]);

        $reservation = app(WalletService::class)->reserve($user, '5000000.01');
        $record = WalletReservation::where('reference', $reservation['reference'])->firstOrFail();

        $this->assertSame(5000000.0, $reservation['wallet']['available_balance']);
        $this->assertSame('5000000.01', $record->amount);
        $this->assertSame(15000, $record->allocation['store_credit']);
        $this->assertSame(4985000.01, $record->allocation['funded']);

        app(WalletService::class)->holdForPayment($user, $record->reference, 'order-high-value');
        app(WalletService::class)->consumePaymentReservation($user, $record->reference, 'order-high-value');

        $wallet->refresh();
        $this->assertSame('5000000.00', $wallet->balance);
        $this->assertSame('0.00', $wallet->store_credit_balance);
        $this->assertSame('0.00', $wallet->reserved_balance);
    }

    public function test_cart_reservation_records_its_credit_sources_and_consumption_updates_every_balance_bucket(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'balance' => 50000,
            'store_credit_balance' => 10000,
            'purchase_cashback_balance' => 6000,
            'referral_available_balance' => 5000,
        ]);

        $reservation = app(WalletService::class)->reserve($user, 40000);
        $record = WalletReservation::where('reference', $reservation['reference'])->firstOrFail();

        $this->assertSame([
            'store_credit' => 10000,
            'funded' => 30000,
            'referral' => 0,
        ], array_map('intval', $record->allocation));
        $this->assertSame(10000.0, $reservation['wallet']['available_balance']);

        app(WalletService::class)->holdForPayment($user, $record->reference, 'order-wallet-1');
        app(WalletService::class)->consumePaymentReservation($user, $record->reference, 'order-wallet-1');

        $wallet->refresh();
        $this->assertSame('10000.00', $wallet->balance);
        $this->assertSame('0.00', $wallet->reserved_balance);
        $this->assertSame('0.00', $wallet->store_credit_balance);
        $this->assertSame('0.00', $wallet->purchase_cashback_balance);
        $this->assertSame('5000.00', $wallet->referral_available_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'reference' => $record->reference,
            'order_id' => 'order-wallet-1',
            'amount' => -40000,
        ]);
    }

    public function test_one_customer_cannot_release_or_consume_another_customers_reservation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Wallet::create(['user_id' => $owner->id, 'currency' => 'NGN', 'balance' => 25000]);
        $reservation = app(WalletService::class)->reserve($owner, 10000);

        $this->actingAs($other)
            ->deleteJson('/api/wallet/cart/reservations/'.$reservation['reference'])
            ->assertNotFound();

        $this->assertDatabaseHas('wallet_reservations', [
            'user_id' => $owner->id,
            'reference' => $reservation['reference'],
            'status' => 'active',
        ]);
    }

    public function test_expired_cart_reservations_release_spendable_credit_but_payment_holds_remain_protected(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => 'NGN', 'balance' => 30000]);
        $cartReservation = app(WalletService::class)->reserve($user, 10000);

        $this->travel(31)->minutes();
        $this->actingAs($user)->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.available_balance', 30000)
            ->assertJsonPath('wallet.reserved_for_cart', 0);
        $this->assertDatabaseHas('wallet_reservations', [
            'reference' => $cartReservation['reference'],
            'status' => 'expired',
        ]);

        $paymentReservation = app(WalletService::class)->reserve($user, 12000);
        app(WalletService::class)->holdForPayment($user, $paymentReservation['reference'], 'order-protected-hold');
        $this->travel(25)->hours();

        $this->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.available_balance', 18000)
            ->assertJsonPath('wallet.reserved_for_cart', 12000);
        $this->assertDatabaseHas('wallet_reservations', [
            'reference' => $paymentReservation['reference'],
            'status' => 'payment_pending',
            'order_id' => 'order-protected-hold',
        ]);
    }

    public function test_incomplete_money_movement_features_are_server_locked_without_changing_balances(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'wallet-recipient@example.com']);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'balance' => 80000,
            'referral_available_balance' => 20000,
        ]);

        $this->actingAs($user)->postJson('/api/wallet/transfers', [
            'recipient' => $recipient->email,
            'amount' => 5000,
        ])->assertStatus(503);
        $this->postJson('/api/wallet/withdrawals', [
            'recipient' => 'unverified-recipient',
            'amount' => 12000,
        ])->assertStatus(503);
        $this->postJson('/api/wallet/referrals/invites', [
            'coupon' => 'CLIENT-CONTROLLED',
            'friend_email' => 'friend@example.com',
            'friend_discount' => '100% off',
            'referrer_reward' => '₦1,000,000 cashback',
        ])->assertStatus(503);
        $this->putJson('/api/wallet/preferences', [
            'auto_recharge_enabled' => true,
            'auto_recharge_amount' => 30000,
            'auto_recharge_frequency' => 'Daily',
            'auto_recharge_card_label' => 'Unverified card',
        ])->assertStatus(503);

        $this->assertSame('80000.00', $wallet->fresh()->balance);
        $this->assertDatabaseCount('wallet_withdrawals', 0);
        $this->assertDatabaseCount('wallet_referral_invites', 0);
        $this->assertDatabaseCount('wallet_preferences', 0);
    }

    public function test_internal_transfer_never_spends_store_only_credit_or_creates_withdrawable_credit_for_the_recipient(): void
    {
        config()->set('wallet.features.transfers', true);
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'safe-transfer@example.com']);
        $senderWallet = Wallet::create([
            'user_id' => $sender->id,
            'currency' => 'NGN',
            'balance' => 40000,
            'store_credit_balance' => 20000,
        ]);

        $this->actingAs($sender)->postJson('/api/wallet/transfers', [
            'recipient' => $recipient->email,
            'amount' => 10000,
        ])->assertCreated();

        $senderWallet->refresh();
        $recipientWallet = Wallet::where('user_id', $recipient->id)->firstOrFail();
        $this->assertSame('29850.00', $senderWallet->balance);
        $this->assertSame('20000.00', $senderWallet->store_credit_balance);
        $this->assertSame('10000.00', $recipientWallet->balance);
        $this->assertSame('10000.00', $recipientWallet->store_credit_balance);
        $this->assertSame('0.00', $recipientWallet->referral_available_balance);

        $this->postJson('/api/wallet/transfers', [
            'recipient' => $recipient->email,
            'amount' => 10000,
        ])->assertUnprocessable();
        $this->assertSame('29850.00', $senderWallet->fresh()->balance);
    }

    public function test_financial_audit_snapshot_survives_customer_and_wallet_deletion(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'NGN']);
        $topUp = WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'provider' => 'paystack',
            'reference' => 'chic_wallet_audit_snapshot',
            'amount' => 10000,
            'bonus_amount' => 500,
            'credit_amount' => 10500,
            'status' => 'pending',
        ]);
        app(WalletService::class)->completeTopUp($topUp->reference, 'paystack-audit-1');

        $this->assertDatabaseHas('wallet_audit_events', [
            'user_id_snapshot' => $user->id,
            'reference' => $topUp->reference,
            'amount' => 10500,
            'balance_after' => 10500,
        ]);

        $user->delete();

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseHas('wallet_audit_events', [
            'user_id_snapshot' => $user->id,
            'reference' => $topUp->reference,
        ]);
    }

    public function test_rewards_catalog_does_not_claim_unissued_rewards_are_earned(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/wallet/rewards')->assertOk();

        $this->assertFalse($response->json('features.withdrawals'));
        $this->assertFalse($response->json('features.transfers'));
        $this->assertTrue($response->json('features.top_up'));
        $this->assertNotContains('Earned', collect($response->json('rewards'))->pluck('status')->all());
    }
}
