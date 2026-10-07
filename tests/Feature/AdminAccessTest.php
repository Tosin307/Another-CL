<?php

namespace Tests\Feature;

use Aimeos\Shop\Controller\AdminController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_admin_visit_uses_the_backend_login(): void
    {
        $this->get('/admin')->assertRedirect('http://localhost:8000/login');
        $this->get('/admin/core')->assertRedirect('http://localhost:8000/login?locale=en');
        $this->get('/login?locale=en')
            ->assertOk()
            ->assertViewIs('auth.admin-login')
            ->assertSee('Administrator sign in.')
            ->assertSee('action="'.route('admin.login.store').'"', false);

        $this->assertSame('http://localhost:8000/admin', session('url.intended'));
    }

    public function test_live_admin_login_stays_on_the_api_backend(): void
    {
        config()->set('app.url', 'https://api.chiclagos.com');
        config()->set('app.frontend_url', 'https://chiclagos.com');

        $this->get('https://api.chiclagos.com/admin')
            ->assertRedirect('https://api.chiclagos.com/login');
        $this->get('https://api.chiclagos.com/login?locale=en')
            ->assertOk()
            ->assertViewIs('auth.admin-login')
            ->assertSee('Enter Chic Lagos admin');

        $this->assertSame('https://api.chiclagos.com/admin', session('url.intended'));
    }

    public function test_successful_backend_login_redirects_to_the_aimeos_admin_panel(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('superuser')->default(false);
        });

        $user = User::factory()->unverified()->create(['superuser' => true]);

        $this->withSession(['url.intended' => 'http://localhost:8000/']);
        $this->get('/login')->assertOk();

        $this->assertSame('http://localhost:8000/admin', session('url.intended'));

        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('http://localhost:8000/admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_superuser_is_authorized_for_the_aimeos_admin_panel(): void
    {
        $user = User::factory()->make();
        $user->setAttribute('superuser', true);

        $this->assertTrue(
            Gate::forUser($user)->allows('admin', [AdminController::class, config('shop.roles')])
        );
    }

    public function test_customer_cannot_use_the_private_backend_login_form(): void
    {
        $user = User::factory()->create();

        $this->get('/login');

        $response = $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_non_superuser_cannot_inherit_admin_or_editor_privileges(): void
    {
        $user = User::factory()->make();
        $user->setAttribute('superuser', false);

        $this->assertFalse(
            Gate::forUser($user)->allows('admin', [AdminController::class, ['admin', 'editor', 'api']])
        );
    }

    public function test_only_superuser_can_open_custom_and_native_admin_surfaces(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('superuser')->default(false);
        });

        $customer = User::factory()->create(['superuser' => false]);
        $superuser = User::factory()->create(['superuser' => true]);

        $this->actingAs($customer)->get('/admin')->assertForbidden();
        $this->actingAs($customer)->get('/admin/products')->assertForbidden();
        $this->actingAs($customer)->get('/admin/core')->assertRedirect('/login?locale=en');

        $this->actingAs($superuser)->get('/admin')
            ->assertOk()
            ->assertViewIs('admin.dashboard')
            ->assertSee('Your business,', false)
            ->assertSee('Open Aimeos core');
    }

    public function test_aimeos_admin_graphql_rejects_a_non_superuser_before_aimeos_runs(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('superuser')->default(false);
        });

        $customer = User::factory()->create(['superuser' => false]);
        $payload = ['query' => 'query { products: searchProducts(limit: 1) { total } }'];

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs($customer)->postJson('/admin/default/graphql', $payload)->assertForbidden();
    }

    public function test_existing_customer_session_can_be_securely_replaced_by_an_admin_login(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('superuser')->default(false);
        });

        $customer = User::factory()->create();
        $admin = User::factory()->unverified()->create(['superuser' => true]);

        $this->actingAs($customer)
            ->withSession(['customer-session-sentinel' => 'must-be-removed'])
            ->get('/login')
            ->assertOk();

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect('http://localhost:8000/admin');

        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('customer-session-sentinel'));
    }

    public function test_existing_customer_session_is_removed_when_admin_credentials_are_wrong(): void
    {
        $customer = User::factory()->create();

        $response = $this->actingAs($customer)
            ->withSession(['customer-session-sentinel' => 'must-be-removed'])
            ->post('/admin/login', [
                'email' => 'missing-admin@example.com',
                'password' => 'wrong-password',
            ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertFalse(session()->has('customer-session-sentinel'));
    }

    public function test_admin_login_can_still_create_a_remember_token(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('superuser')->default(false);
        });

        $admin = User::factory()->unverified()->create(['superuser' => true]);

        $this->get('/login');
        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect('http://localhost:8000/admin');

        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull($admin->fresh()->getRememberToken());
    }
}
