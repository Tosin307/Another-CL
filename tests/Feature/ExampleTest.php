<?php

namespace Tests\Feature;

use App\Support\ChicLagosFlashSales;
use App\Support\ChicLagosBogofOffers;
use App\Support\ChicLagosVolumeOffers;
// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_nneka_flash_sale_uses_the_configured_discount(): void
    {
        $sale = ChicLagosFlashSales::forProduct('chiclagos-nneka-belted-shirt-dress', 39500);

        $this->assertNotNull($sale);
        $this->assertSame(12, $sale['discountPercent']);
        $this->assertSame(34760.0, $sale['salePrice']);
    }

    public function test_imani_offer_assigns_kemi_as_one_complimentary_gift(): void
    {
        $offer = ChicLagosBogofOffers::activeForProduct('chiclagos-imani-wrap-dress');

        $this->assertNotNull($offer);
        $this->assertSame('chiclagos-kemi-column-dress', $offer['giftProductCode']);
        $this->assertSame(1, $offer['maxGiftQuantity']);
        $this->assertSame('chiclagos-imani-wrap-dress', ChicLagosBogofOffers::forGift('chiclagos-kemi-column-dress')['qualifyingProductCode']);
    }

    public function test_lola_volume_offer_uses_quantity_thresholds(): void
    {
        $this->assertSame(0, ChicLagosVolumeOffers::discountPercent('chiclagos-lola-contrast-jumpsuit', 2));
        $this->assertSame(5, ChicLagosVolumeOffers::discountPercent('chiclagos-lola-contrast-jumpsuit', 4));
        $this->assertSame(7, ChicLagosVolumeOffers::discountPercent('chiclagos-lola-contrast-jumpsuit', 9));
        $this->assertSame(10, ChicLagosVolumeOffers::discountPercent('chiclagos-lola-contrast-jumpsuit', 14));
        $this->assertSame(12, ChicLagosVolumeOffers::discountPercent('chiclagos-lola-contrast-jumpsuit', 15));
    }

}
