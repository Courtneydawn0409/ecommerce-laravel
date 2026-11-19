<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Factories\CarrierRateFactory;
use App\Services\Shipping\CarrierRate;
use App\Services\Shipping\EasyPostCarrier;
use App\Services\Shipping\OrchestratedCarrier;
use App\Services\ShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Liberu\Shipping\Contracts\Enums\ShipmentLifecycleState;
use Tests\TestCase;

class ShippingCoreIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_core_migrations_create_tables(): void
    {
        $this->assertTrue(Schema::hasTable('shipping_core_shipments'));
        $this->assertTrue(Schema::hasTable('shipping_core_webhook_inbox'));
    }

    public function test_carrier_rate_factory_resolves_all_registered_providers(): void
    {
        $this->assertInstanceOf(EasyPostCarrier::class, CarrierRateFactory::create('easypost'));

        foreach (['shippo', 'shipengine', 'ups', 'fedex', 'dhl', 'royalmail'] as $provider) {
            $resolved = CarrierRateFactory::create($provider);
            $this->assertInstanceOf(OrchestratedCarrier::class, $resolved);
            $this->assertSame($provider, $resolved->providerKey());
        }

        $this->assertNull(CarrierRateFactory::create('unknown-provider'));
    }

    public function test_shipping_service_gets_live_rates_from_shippo_provider(): void
    {
        config([
            'shipping.carrier' => 'shippo',
            'shipping.shippo.api_token' => 'shippo_test_123',
        ]);

        Http::fake([
            'api.goshippo.com/shipments/' => Http::response([
                'object_id' => 'shp_1',
                'rates' => [
                    ['object_id' => 'r_fast', 'provider' => 'UPS', 'servicelevel' => ['name' => 'Next Day'], 'amount' => '24.50', 'currency' => 'USD', 'estimated_days' => 1],
                    ['object_id' => 'r_cheap', 'provider' => 'USPS', 'servicelevel' => ['name' => 'Priority'], 'amount' => '8.95', 'currency' => 'USD', 'estimated_days' => 3],
                ],
            ]),
        ]);

        $rates = app(ShippingService::class)->getLiveRates(
            [1 => ['quantity' => 1, 'weight' => 16.0]],
            ['zip' => '10001', 'country' => 'US'],
        );

        $this->assertCount(2, $rates);
        $this->assertContainsOnlyInstancesOf(CarrierRate::class, $rates);
        $this->assertSame('USPS', $rates[0]->carrier);
        $this->assertSame(8.95, $rates[0]->amount);
    }

    public function test_shipping_service_delegates_shipment_creation_tracking_and_address_verification(): void
    {
        config([
            'shipping.carrier' => 'shipengine',
            'shipping.shipengine.api_key' => 'se_test_123',
        ]);

        Http::fake([
            'api.shipengine.com/v1/labels/rates/se-r1' => Http::response([
                'label_id' => 'se-lbl-99',
                'carrier_code' => 'ups',
                'service_code' => 'ups_ground',
                'tracking_number' => '1ZSE999',
                'label_download' => ['pdf' => 'https://labels.test/se-lbl-99.pdf'],
            ]),
            'api.shipengine.com/v1/tracking*' => Http::response([
                'tracking_number' => '1ZSE999',
                'carrier_code' => 'ups',
                'status_code' => 'DE',
                'events' => [],
            ]),
            'api.shipengine.com/v1/addresses/validate' => Http::response([
                ['status' => 'verified', 'matched_address' => ['address_line1' => '100 MAIN ST', 'city_locality' => 'AUSTIN', 'state_province' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'], 'messages' => []],
            ]),
        ]);

        $service = app(ShippingService::class);

        $shipment = $service->createShipment(
            parcel: ['weight' => 16.0],
            to: ['street1' => '100 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'country' => 'US'],
            rateId: 'se-r1',
        );
        $this->assertSame('se-lbl-99', $shipment->providerShipmentId);
        $this->assertSame('1ZSE999', $shipment->trackingNumber);

        $tracking = $service->trackShipment('1ZSE999', 'ups');
        $this->assertSame(ShipmentLifecycleState::Delivered, $tracking->state);

        $verified = $service->verifyAddress(['street1' => '100 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'country' => 'US']);
        $this->assertTrue($verified['valid']);
        $this->assertSame('shipengine', $verified['provider']);
    }
}
