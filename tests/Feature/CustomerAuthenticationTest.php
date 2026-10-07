<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_unverified_account_and_sends_verification_mail(): void
    {
        Notification::fake();

        $response = $this->postJson('/register', [
            'name' => 'New Customer',
            'email' => 'new-customer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'marketing_opt_in' => true,
        ]);

        $response->assertCreated();

        $user = User::where('email', 'new-customer@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Customer',
            'marketing_opt_in' => 1,
        ]);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_customers_cannot_sign_in_or_read_their_account(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertForbidden();

        $this->assertGuest();
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertForbidden();
    }

    public function test_verified_customers_can_sign_in_read_their_account_and_sign_out(): void
    {
        $user = User::factory()->create();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJson(['two_factor' => false]);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJson([
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ]);

        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_customer_can_request_and_use_a_password_reset(): void
    {
        Notification::fake();
        $oldRememberToken = Str::random(60);
        $user = User::factory()->create(['remember_token' => $oldRememberToken]);

        $this->postJson('/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::broker()->createToken($user);

        $this->postJson('/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $freshUser = $user->fresh();

        $this->assertTrue(Hash::check('new-password', $freshUser->password));
        $this->assertNotSame($oldRememberToken, $freshUser->getRememberToken());
        $this->assertFalse(Password::broker()->tokenExists($freshUser, $token));

        $this->postJson('/login', [
            'email' => $freshUser->email,
            'password' => 'password',
        ])->assertUnprocessable();

        $this->postJson('/login', [
            'email' => $freshUser->email,
            'password' => 'new-password',
        ])->assertOk();
    }
}
