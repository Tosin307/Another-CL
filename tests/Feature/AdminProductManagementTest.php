<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ProductLatestEditEntry;
use App\Services\ProductLatestEditService;
use App\Services\ProductAdminWriteService;
use App\Services\ProductMerchandisingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('users', 'superuser')) {
            Schema::table('users', fn (Blueprint $table) => $table->boolean('superuser')->default(false));
        }
    }

    public function test_only_superuser_can_reach_every_product_management_route(): void
    {
        $customer = User::factory()->create(['superuser' => false]);

        $this->actingAs($customer)->get('/admin/products')->assertForbidden();
        $this->actingAs($customer)->get('/admin/products/create')->assertForbidden();
        $this->actingAs($customer)->post('/admin/products', [])->assertForbidden();
        $this->actingAs($customer)->get('/admin/products/18')->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18', [])->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18/stock', [])->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18/categories', [])->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18/sizes', [])->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18/media', [])->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18/merchandising', [])->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18/latest-edit', [])->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18/information', [])->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18/swatches', [])->assertForbidden();
        $this->actingAs($customer)->post('/admin/products/18/reviews', [])->assertForbidden();
        $this->actingAs($customer)->patch('/admin/products/18/reviews/1', [])->assertForbidden();
        $this->actingAs($customer)->delete('/admin/products/18/reviews/1')->assertForbidden();
        $this->actingAs($customer)->get('/admin/storefront-media')->assertForbidden();
        $this->actingAs($customer)->patch('/admin/storefront-media/home.hero', [])->assertForbidden();
    }

    public function test_product_directory_renders_secure_search_filters_and_honest_empty_state(): void
    {
        $admin = User::factory()->create(['superuser' => true]);

        $response = $this->actingAs($admin)->get('/admin/products');

        $response->assertOk()
            ->assertViewIs('admin.products.index')
            ->assertSee('Every piece.', false)
            ->assertSee('ca-nav-group', false)
            ->assertSee('Add product')
            ->assertSee('Search product name, code, ID, colour or size')
            ->assertSee('Parent pieces')
            ->assertSee('Colour variants')
            ->assertSee('The live Aimeos catalogue could not be read')
            ->assertSee('aspect-ratio: 9 / 16;', false)
            ->assertSee('object-fit: contain;', false);
    }

    public function test_product_directory_rejects_unrecognised_filters(): void
    {
        $admin = User::factory()->create(['superuser' => true]);

        $this->actingAs($admin)
            ->from('/admin/products')
            ->get('/admin/products?scope=foreign&sort=unsafe')
            ->assertRedirect('/admin/products')
            ->assertSessionHasErrors(['scope', 'sort']);
    }

    public function test_product_creation_gives_the_superuser_an_explicit_draft_or_publish_choice(): void
    {
        $admin = User::factory()->create(['superuser' => true]);

        $this->actingAs($admin)->get('/admin/products/create')
            ->assertOk()
            ->assertViewIs('admin.products.create')
            ->assertSee('Draft or publish')
            ->assertSee('Save as draft')
            ->assertSee('Create &amp; publish', false)
            ->assertSee('Publishing requires a positive price, at least one category and at least one image or video')
            ->assertSee('MP4/WebM videos up to 50 MB')
            ->assertSee('Available sizes · independent of colour')
            ->assertSee('Size 8')
            ->assertDontSee('UK 8')
            ->assertSee('Colour variants, swatches & stock', false)
            ->assertSee('variant_media[0][]', false)
            ->assertSee('variant_posters[0][]', false)
            ->assertSee('Add another colour')
            ->assertSee('Primary hex')
            ->assertSee('Primary RGB')
            ->assertSee('Secondary hex')
            ->assertSee('Secondary RGB')
            ->assertSee('Information panels')
            ->assertSee('Buy One Get One Free')
            ->assertSee('Buy More Save More')
            ->assertSee('Initial review & Latest Edit', false)
            ->assertSee('aspect-ratio: 9 / 16;', false);

        $this->actingAs($admin)->from('/admin/products/create')->post('/admin/products', [])
            ->assertRedirect('/admin/products/create')
            ->assertSessionHasErrors(['label', 'sku', 'code', 'type']);
    }

    public function test_immediate_publication_requires_a_complete_simple_product(): void
    {
        $admin = User::factory()->create(['superuser' => true]);

        $this->actingAs($admin)->from('/admin/products/create')->post('/admin/products', [
            'label' => 'Incomplete Simple Product',
            'type' => 'default',
            'status' => 1,
            'currency' => 'NGN',
        ])->assertRedirect('/admin/products/create')
            ->assertSessionHasErrors(['price', 'category_ids', 'media']);

        $this->actingAs($admin)->from('/admin/products/create')->post('/admin/products', [
            'label' => 'Unfinished Colour Product',
            'type' => 'select',
            'status' => 1,
            'price' => 120000,
            'currency' => 'NGN',
            'category_ids' => [16],
        ])->assertRedirect('/admin/products/create')
            ->assertSessionHasErrors(['status', 'media']);
    }

    public function test_size_editor_accepts_numeric_chic_lagos_sizes_for_a_simple_product(): void
    {
        $admin = User::factory()->create(['superuser' => true]);
        $writer = \Mockery::mock(ProductAdminWriteService::class);
        $writer->shouldReceive('updateSizes')->once()->with(
            '74',
            ['8', '10', '12', '14', '16', '18'],
            \Mockery::on(fn ($user) => $user->is($admin)),
        )->andReturn([]);
        $this->app->instance(ProductAdminWriteService::class, $writer);

        $this->actingAs($admin)->patch('/admin/products/74/sizes', [
            'sizes' => ['8', '10', '12', '14', '16', '18'],
        ])->assertRedirect('/admin/products/74#sizes')->assertSessionHasNoErrors();
    }

    public function test_information_panel_key_accepts_friendly_punctuation_and_is_normalized_before_save(): void
    {
        $admin = User::factory()->create(['superuser' => true]);
        $merchandising = \Mockery::mock(ProductMerchandisingService::class);
        $merchandising->shouldReceive('updateInformationPanels')->once()->with(
            '74',
            \Mockery::on(fn ($panels) => data_get($panels, '0.panel_key') === 'fit-shape-care'
                && data_get($panels, '0.title') === 'Fit & shape'),
            \Mockery::on(fn ($user) => $user->is($admin)),
        );
        $this->app->instance(ProductMerchandisingService::class, $merchandising);

        $this->actingAs($admin)->patch('/admin/products/74/information', [
            'panels' => [[
                'panel_key' => 'Fit & Shape / Care',
                'title' => 'Fit & shape',
                'teaser' => 'A considered fit.',
                'body' => 'Designed to follow the body without restricting movement.',
                'tags' => "Fitted\nFluid",
                'details' => 'Length: Midi',
                'active' => '1',
            ]],
        ])->assertRedirect('/admin/products/74#information-panels')->assertSessionHasNoErrors();
    }

    public function test_double_swatch_editor_preserves_the_optional_second_colour(): void
    {
        $admin = User::factory()->create(['superuser' => true]);
        $merchandising = \Mockery::mock(ProductMerchandisingService::class);
        $merchandising->shouldReceive('updateSwatches')->once()->with(
            '24',
            \Mockery::on(fn ($variants) => data_get($variants, '56.primary') === '#e9dfd4'
                && data_get($variants, '56.secondary') === '#171717'
                && data_get($variants, '56.secondary_enabled') === true),
            \Mockery::on(fn ($user) => $user->is($admin)),
        );
        $this->app->instance(ProductMerchandisingService::class, $merchandising);

        $this->actingAs($admin)->patch('/admin/products/24/swatches', [
            'variants' => ['56' => [
                'primary' => '#e9dfd4',
                'primary_hex' => '#e9dfd4',
                'primary_rgb' => 'rgb(233, 223, 212)',
                'secondary_enabled' => '1',
                'secondary' => '#171717',
                'secondary_hex' => '#171717',
                'secondary_rgb' => 'rgb(23, 23, 23)',
            ]],
        ])->assertRedirect('/admin/products/24#variants')->assertSessionHasNoErrors();
    }

    public function test_product_editor_validates_price_content_and_stock_reason_before_writes(): void
    {
        $admin = User::factory()->create(['superuser' => true]);

        $this->actingAs($admin)->from('/admin/products/18')->patch('/admin/products/18', [
            'label' => '',
            'status' => 9,
            'url' => 'Unsafe URL?',
            'price' => -1,
            'rebate' => 4,
            'currency' => 'NAIRA',
        ])->assertRedirect('/admin/products/18')->assertSessionHasErrors(['label', 'status', 'url', 'price', 'currency']);

        $this->actingAs($admin)->from('/admin/products/18#inventory')->patch('/admin/products/18/stock', [
            'stocks' => ['999' => -1],
            'reason' => 'because',
        ])->assertRedirect('/admin/products/18#inventory')->assertSessionHasErrors(['stocks.999', 'reason']);
    }

    public function test_product_audit_schema_keeps_history_without_duplicating_aimeos_products(): void
    {
        $this->assertTrue(Schema::hasColumns('product_admin_activities', [
            'user_id', 'product_id', 'product_code', 'action', 'section', 'before_values', 'after_values', 'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('product_stock_adjustments', [
            'user_id', 'product_id', 'product_code', 'stock_type', 'quantity_before', 'quantity_after', 'quantity_delta', 'reason', 'note',
        ]));
        $this->assertTrue(Schema::hasColumns('product_merchandising_offers', [
            'product_id', 'product_code', 'offer_type', 'active', 'related_product_id', 'thresholds', 'counter_enabled',
        ]));
        $this->assertTrue(Schema::hasColumns('product_information_panels', [
            'product_id', 'product_code', 'panel_key', 'title', 'teaser', 'body', 'tags', 'details', 'position', 'active',
        ]));
        $this->assertTrue(Schema::hasColumns('product_review_metadata', [
            'review_id', 'product_id', 'reviewer_email', 'title', 'location', 'size', 'media_path', 'media_type', 'verified_purchase',
        ]));
        $this->assertTrue(Schema::hasColumns('product_merchandising_events', [
            'event_key', 'user_id', 'product_id', 'product_code', 'offer_type', 'event_type', 'quantity', 'amount', 'currency', 'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('product_latest_edit_entries', [
            'product_id', 'product_code', 'included_at', 'expires_at', 'auto_expire',
            'removed_at', 'removal_reason', 'created_by', 'updated_by', 'removed_by',
        ]));
        $this->assertTrue(Schema::hasColumns('storefront_media', [
            'placement', 'label', 'media_path', 'media_type', 'mime_type', 'poster_path',
            'position', 'enabled', 'created_by', 'updated_by',
        ]));
        $this->assertFalse(Schema::hasTable('product_admin_products'));
    }

    public function test_storefront_media_admin_accepts_browser_video_with_a_required_poster(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['superuser' => true]);
        $videoPath = base_path('../chiclagos-frontend/public/fashion-film.mp4');
        $posterPath = base_path('../chiclagos-frontend/public/shop-products/sade-pink-front.webp');

        $this->actingAs($admin)->get('/admin/storefront-media')
            ->assertOk()
            ->assertSee('MP4 or WebM up to 50 MB')
            ->assertSee('Product, colour-article, Shop, Latest Edit and recommendation media continue to come from Aimeos.');

        $this->actingAs($admin)
            ->from('/admin/storefront-media')
            ->patch('/admin/storefront-media/home.hero', [
                'label' => 'Local video verification',
                'media' => new UploadedFile($videoPath, 'fashion-film.mp4', 'video/mp4', null, true),
                'enabled' => '1',
            ])
            ->assertRedirect('/admin/storefront-media')
            ->assertSessionHasErrors(['poster']);

        $this->actingAs($admin)
            ->patch('/admin/storefront-media/home.hero', [
                'label' => 'Local video verification',
                'media' => new UploadedFile($videoPath, 'fashion-film.mp4', 'video/mp4', null, true),
                'poster' => new UploadedFile($posterPath, 'poster.webp', 'image/webp', null, true),
                'enabled' => '1',
            ])
            ->assertRedirect('/admin/storefront-media#home-hero')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('storefront_media', [
            'placement' => 'home.hero',
            'media_type' => 'video',
            'mime_type' => 'video/mp4',
            'enabled' => true,
        ]);
        $this->getJson('/api/storefront-media')
            ->assertOk()
            ->assertJsonFragment([
                'placement' => 'home.hero',
                'type' => 'video',
                'mime' => 'video/mp4',
                'managed' => true,
            ]);
    }

    public function test_latest_edit_admin_control_requires_a_valid_removal_mode_and_future_expiry(): void
    {
        $admin = User::factory()->create(['superuser' => true]);

        $this->actingAs($admin)->from('/admin/products/18#latest-edit')->patch('/admin/products/18/latest-edit', [
            'active' => '1',
            'mode' => 'automatic',
            'expires_at' => now()->subDay()->toDateTimeString(),
        ])->assertRedirect('/admin/products/18#latest-edit')->assertSessionHasErrors(['expires_at']);

        $this->actingAs($admin)->from('/admin/products/18#latest-edit')->patch('/admin/products/18/latest-edit', [
            'active' => '1',
            'mode' => 'delete-product',
        ])->assertRedirect('/admin/products/18#latest-edit')->assertSessionHasErrors(['mode']);
    }

    public function test_latest_edit_feed_selects_only_live_automatic_or_manual_placements(): void
    {
        ProductLatestEditEntry::query()->create([
            'product_id' => '101', 'product_code' => 'chiclagos-newest',
            'included_at' => now(), 'expires_at' => now()->addDays(30), 'auto_expire' => true,
        ]);
        ProductLatestEditEntry::query()->create([
            'product_id' => '102', 'product_code' => 'chiclagos-manual',
            'included_at' => now()->subMinute(), 'expires_at' => null, 'auto_expire' => false,
        ]);
        ProductLatestEditEntry::query()->create([
            'product_id' => '103', 'product_code' => 'chiclagos-expired',
            'included_at' => now()->subDays(31), 'expires_at' => now()->subDay(), 'auto_expire' => true,
        ]);
        ProductLatestEditEntry::query()->create([
            'product_id' => '104', 'product_code' => 'chiclagos-removed',
            'included_at' => now(), 'expires_at' => now()->addDays(30), 'auto_expire' => true,
            'removed_at' => now(), 'removal_reason' => 'manual',
        ]);

        $this->assertSame(['101', '102'], app(ProductLatestEditService::class)->activeProductIds());
    }

    public function test_latest_edit_expiry_command_is_safe_when_nothing_is_due(): void
    {
        $this->artisan('products:expire-latest-edit')
            ->expectsOutput('Expired 0 Latest Edit placement(s); 0 failed.')
            ->assertSuccessful();
    }

    public function test_customer_facing_category_configuration_matches_the_storefront_directory(): void
    {
        $categories = collect(config('product-admin.categories'));

        $this->assertSame(
            ['Dresses', 'Tops', 'Bottoms', 'Playsuits', 'Jumpsuits', 'Accessories'],
            $categories->pluck('label')->all(),
        );
        $this->assertSame(
            ['dresses', 'tops', 'bottoms', 'playsuits', 'jumpsuits', 'accessories'],
            $categories->pluck('slug')->all(),
        );
        $this->assertNotContains('chiclagos', $categories->pluck('code')->all());
    }

    public function test_public_review_submission_requires_real_moderation_fields_and_media(): void
    {
        $this->postJson('/api/products/chiclagos-sade-sculpted-dress/reviews', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'rating' => 5,
            'comment' => 'The fit and movement are beautiful.',
            'title' => 'Beautiful fit',
            'location' => 'Lagos',
            'size' => 'UK 12',
        ])->assertUnprocessable()->assertJsonValidationErrors(['photo', 'video']);
    }
}
