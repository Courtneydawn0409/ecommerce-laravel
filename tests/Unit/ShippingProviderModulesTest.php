<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Shipping\Contracts\Contracts\ShippingProviderRegistryContract;
use Liberu\Shipping\Contracts\DTOs\AddressDto;
use Liberu\Shipping\Contracts\DTOs\CancelShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\CreateShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\GetRatesCommand;
use Liberu\Shipping\Contracts\DTOs\GetTrackingCommand;
use Liberu\Shipping\Contracts\DTOs\MoneyAmount;
use Liberu\Shipping\Contracts\DTOs\ParcelDto;
use Liberu\Shipping\Contracts\DTOs\RawWebhookPayloadDto;
use Liberu\Shipping\Contracts\DTOs\ShippingAccountContextDto;
use Liberu\Shipping\Contracts\DTOs\ValidateAddressCommand;
use Liberu\Shipping\Contracts\Enums\ShipmentLifecycleState;
use Liberu\Shipping\Core\Services\ShippingOrchestrator;
use RuntimeException;
use Tests\TestCase;

class ShippingProviderModulesTest extends TestCase
{
    private AddressDto $from;

    private AddressDto $to;

    private ParcelDto $parcel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->from = new AddressDto(
            name: 'Warehouse',
            street1: '417 Montgomery St',
            city: 'San Francisco',
            state: 'CA',
            postalCode: '94104',
            country: 'US',
        );

        $this->to = new AddressDto(
            name: 'Jane Customer',
            street1: '350 5th Ave',
            city: 'New York',
            state: 'NY',
            postalCode: '10118',
            country: 'US',
        );

        $this->parcel = new ParcelDto(weight: 32.0, weightUnit: 'oz', length: 10.0, width: 8.0, height: 4.0);
    }

    public function test_all_shipping_modules_boot_and_register_with_registry(): void
    {
        $inventory = app(ModuleInventory::class);

        foreach ([
            'shipping-contracts',
            'shipping-core',
            'shipping-easypost',
            'shipping-shippo',
            'shipping-shipengine',
            'shipping-ups',
            'shipping-fedex',
            'shipping-dhl',
            'shipping-royalmail',
        ] as $module) {
            $this->assertTrue($inventory->isBooted($module), "Module [{$module}] should be booted.");
        }

        $registry = app(ShippingProviderRegistryContract::class);
        foreach (['easypost', 'shippo', 'shipengine', 'ups', 'fedex', 'dhl', 'royalmail'] as $provider) {
            $this->assertTrue($registry->has($provider), "Provider [{$provider}] should be registered.");
        }

        $descriptors = app(ShippingOrchestrator::class)->availableProviders();
        $this->assertCount(7, $descriptors);
        $this->assertTrue($descriptors['easypost']->isAggregator);
        $this->assertTrue($descriptors['shippo']->isAggregator);
        $this->assertTrue($descriptors['shipengine']->isAggregator);
        $this->assertFalse($descriptors['ups']->isAggregator);
        $this->assertFalse($descriptors['fedex']->isAggregator);
        $this->assertFalse($descriptors['dhl']->isAggregator);
        $this->assertFalse($descriptors['royalmail']->isAggregator);
    }

    public function test_parcel_and_money_dto_conversions(): void
    {
        $lb = new ParcelDto(weight: 2.0, weightUnit: 'lb');
        $this->assertSame(32.0, $lb->weightInOunces());
        $this->assertSame(2.0, $lb->weightInPounds());

        $kg = new ParcelDto(weight: 1.0, weightUnit: 'kg');
        $this->assertSame(1000, $kg->weightInGrams());
        $this->assertSame(1.0, $kg->weightInKilograms());

        $money = MoneyAmount::fromMajor('14.95', 'gbp');
        $this->assertSame(1495, $money->amountMinor);
        $this->assertSame('GBP', $money->currency);
        $this->assertSame(14.95, $money->toMajor());
    }

    public function test_registry_throws_on_unknown_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ShippingProviderRegistryContract::class)->resolve('nonexistent-carrier');
    }

    public function test_orchestrator_rejects_unsupported_capability(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support address validation');

        app(ShippingOrchestrator::class)->validateAddress(new ValidateAddressCommand(
            account: new ShippingAccountContextDto('tenant-1', 'royalmail', ['api_key' => 'rm_key']),
            address: $this->to,
        ));
    }

    public function test_easypost_adapter_full_lifecycle_and_webhook_verification(): void
    {
        Http::fake([
            'api.easypost.com/v2/shipments' => Http::response([
                'id' => 'shp_ep_1',
                'rates' => [
                    ['id' => 'rate_2', 'carrier' => 'UPS', 'service' => 'Ground', 'rate' => '14.50', 'currency' => 'USD', 'delivery_days' => 4],
                    ['id' => 'rate_1', 'carrier' => 'USPS', 'service' => 'Priority', 'rate' => '8.25', 'currency' => 'USD', 'delivery_days' => 2],
                ],
            ]),
            'api.easypost.com/v2/shipments/shp_ep_1/buy' => Http::response([
                'id' => 'shp_ep_1',
                'tracking_code' => '9400111899223',
                'selected_rate' => ['carrier' => 'USPS', 'service' => 'Priority', 'rate' => '8.25', 'currency' => 'USD'],
                'postage_label' => ['label_url' => 'https://labels.easypost.test/1.pdf', 'label_file_type' => 'PDF'],
                'tracker' => ['public_url' => 'https://track.easypost.test/1'],
            ]),
            'api.easypost.com/v2/shipments/shp_ep_1/refund' => Http::response(['refund_status' => 'submitted']),
            'api.easypost.com/v2/trackers' => Http::response([
                'tracking_code' => '9400111899223',
                'carrier' => 'USPS',
                'status' => 'in_transit',
                'tracking_details' => [
                    ['message' => 'Arrived at hub', 'status' => 'in_transit', 'datetime' => '2026-11-19T08:00:00Z', 'tracking_location' => ['city' => 'Newark', 'state' => 'NJ', 'country' => 'US']],
                ],
            ]),
            'api.easypost.com/v2/addresses/create_and_verify' => Http::response([
                'address' => [
                    'street1' => '350 5TH AVE',
                    'city' => 'NEW YORK',
                    'state' => 'NY',
                    'zip' => '10118-0110',
                    'country' => 'US',
                    'verifications' => ['delivery' => ['success' => true, 'errors' => []]],
                ],
            ]),
        ]);

        $orchestrator = app(ShippingOrchestrator::class);
        $account = new ShippingAccountContextDto('t1', 'easypost', ['api_key' => 'ek_test']);

        $rates = $orchestrator->getRates(new GetRatesCommand($account, $this->from, $this->to, $this->parcel));
        $this->assertCount(2, $rates);
        $this->assertSame('USPS', $rates[0]->carrier);
        $this->assertSame(825, $rates[0]->amount->amountMinor);

        $shipment = $orchestrator->createShipment(new CreateShipmentCommand(
            account: $account,
            from: $this->from,
            to: $this->to,
            parcel: $this->parcel,
            idempotencyKey: 'ep_buy_1',
            rateId: 'rate_1',
            metadata: ['shipment_id' => 'shp_ep_1'],
        ));
        $this->assertSame(ShipmentLifecycleState::LabelPurchased, $shipment->state);
        $this->assertSame('9400111899223', $shipment->trackingNumber);

        $cancel = $orchestrator->cancelShipment(new CancelShipmentCommand($account, 'shp_ep_1', 'cancel_1'));
        $this->assertTrue($cancel->refunded);
        $this->assertSame(ShipmentLifecycleState::Cancelled, $cancel->state);

        $tracking = $orchestrator->getTracking(new GetTrackingCommand($account, '9400111899223', 'USPS'));
        $this->assertSame(ShipmentLifecycleState::InTransit, $tracking->state);
        $this->assertCount(1, $tracking->events);

        $validation = $orchestrator->validateAddress(new ValidateAddressCommand($account, $this->to));
        $this->assertTrue($validation->valid);
        $this->assertSame('10118-0110', $validation->normalizedAddress?->postalCode);

        $rawBody = json_encode([
            'id' => 'evt_ep_1',
            'description' => 'tracker.updated',
            'result' => ['id' => 'trk_1', 'tracking_code' => '9400111899223', 'status' => 'delivered'],
        ], JSON_THROW_ON_ERROR);
        $sig = 'hmac-sha256-hex='.hash_hmac('sha256', $rawBody, 'whsec_test');

        $event = $orchestrator->verifyWebhook(new RawWebhookPayloadDto(
            providerKey: 'easypost',
            rawBody: $rawBody,
            headers: ['X-Hmac-Signature' => $sig],
            signingSecret: 'whsec_test',
        ));
        $this->assertSame('evt_ep_1', $event->providerEventId);
        $this->assertSame(ShipmentLifecycleState::Delivered, $event->targetState);
    }

    public function test_shippo_adapter_rates_shipment_tracking_and_validation(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::response([
                'object_id' => 'shp_po_1',
                'rates' => [
                    ['object_id' => 'rate_po_2', 'provider' => 'FedEx', 'servicelevel' => ['name' => '2Day'], 'amount' => '19.00', 'currency' => 'USD', 'estimated_days' => 2],
                    ['object_id' => 'rate_po_1', 'provider' => 'USPS', 'servicelevel' => ['name' => 'Ground Advantage'], 'amount' => '6.40', 'currency' => 'USD', 'estimated_days' => 5],
                ],
            ]),
            'api.goshippo.com/transactions/' => Http::response([
                'object_id' => 'tx_po_1',
                'status' => 'SUCCESS',
                'tracking_number' => 'SHIPPO_TRK_1',
                'label_url' => 'https://labels.goshippo.test/1.pdf',
            ]),
            'api.goshippo.com/refunds/' => Http::response(['status' => 'QUEUED']),
            'api.goshippo.com/tracks/usps/SHIPPO_TRK_1' => Http::response([
                'tracking_number' => 'SHIPPO_TRK_1',
                'carrier' => 'usps',
                'tracking_status' => ['status' => 'DELIVERED'],
                'tracking_history' => [['status' => 'DELIVERED', 'status_details' => 'Left at front door', 'status_date' => '2026-11-19T09:00:00Z']],
            ]),
            'api.goshippo.com/addresses/' => Http::response([
                'street1' => '350 5th Ave',
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10118',
                'country' => 'US',
                'validation_results' => ['is_valid' => true, 'messages' => []],
            ]),
        ]);

        $orchestrator = app(ShippingOrchestrator::class);
        $account = new ShippingAccountContextDto('t1', 'shippo', ['api_token' => 'shippo_test_tok']);

        $rates = $orchestrator->getRates(new GetRatesCommand($account, $this->from, $this->to, $this->parcel));
        $this->assertCount(2, $rates);
        $this->assertSame('USPS', $rates[0]->carrier);
        $this->assertSame(640, $rates[0]->amount->amountMinor);

        $shipment = $orchestrator->createShipment(new CreateShipmentCommand($account, $this->from, $this->to, $this->parcel, 'po_1', rateId: 'rate_po_1'));
        $this->assertSame(ShipmentLifecycleState::LabelPurchased, $shipment->state);
        $this->assertSame('SHIPPO_TRK_1', $shipment->trackingNumber);

        $cancel = $orchestrator->cancelShipment(new CancelShipmentCommand($account, 'tx_po_1', 'cancel_po_1'));
        $this->assertTrue($cancel->refunded);

        $tracking = $orchestrator->getTracking(new GetTrackingCommand($account, 'SHIPPO_TRK_1', 'usps'));
        $this->assertSame(ShipmentLifecycleState::Delivered, $tracking->state);

        $validation = $orchestrator->validateAddress(new ValidateAddressCommand($account, $this->to));
        $this->assertTrue($validation->valid);
    }

    public function test_shipengine_adapter_rates_label_void_tracking_and_validation(): void
    {
        Http::fake([
            'api.shipengine.com/v1/rates' => Http::response([
                'shipment_id' => 'se-1',
                'rate_response' => [
                    'rates' => [
                        ['rate_id' => 'se-rate-1', 'carrier_friendly_name' => 'USPS', 'service_type' => 'USPS Priority Mail', 'shipping_amount' => ['amount' => 9.15, 'currency' => 'usd'], 'delivery_days' => 2],
                    ],
                ],
            ]),
            'api.shipengine.com/v1/labels/rates/se-rate-1' => Http::response([
                'label_id' => 'se-lbl-1',
                'carrier_code' => 'stamps_com',
                'service_code' => 'usps_priority_mail',
                'tracking_number' => 'SE123456',
                'label_download' => ['pdf' => 'https://labels.shipengine.test/se-lbl-1.pdf'],
                'shipment_cost' => ['amount' => 9.15, 'currency' => 'usd'],
            ]),
            'api.shipengine.com/v1/labels/se-lbl-1/void' => Http::response(['approved' => true, 'message' => 'Voided']),
            'api.shipengine.com/v1/tracking*' => Http::response([
                'tracking_number' => 'SE123456',
                'carrier_code' => 'stamps_com',
                'status_code' => 'IT',
                'events' => [['description' => 'In transit', 'status_code' => 'IT', 'occurred_at' => '2026-11-19T07:00:00Z']],
            ]),
            'api.shipengine.com/v1/addresses/validate' => Http::response([
                ['status' => 'verified', 'matched_address' => ['address_line1' => '350 5TH AVE', 'city_locality' => 'NEW YORK', 'state_province' => 'NY', 'postal_code' => '10118', 'country_code' => 'US'], 'messages' => []],
            ]),
        ]);

        $orchestrator = app(ShippingOrchestrator::class);
        $account = new ShippingAccountContextDto('t1', 'shipengine', ['api_key' => 'se_test_key', 'carrier_ids' => ['se-123']]);

        $rates = $orchestrator->getRates(new GetRatesCommand($account, $this->from, $this->to, $this->parcel));
        $this->assertCount(1, $rates);
        $this->assertSame(915, $rates[0]->amount->amountMinor);

        $shipment = $orchestrator->createShipment(new CreateShipmentCommand($account, $this->from, $this->to, $this->parcel, 'se_1', rateId: 'se-rate-1'));
        $this->assertSame('se-lbl-1', $shipment->providerShipmentId);
        $this->assertSame('SE123456', $shipment->trackingNumber);

        $cancel = $orchestrator->cancelShipment(new CancelShipmentCommand($account, 'se-lbl-1', 'void_1'));
        $this->assertTrue($cancel->refunded);

        $tracking = $orchestrator->getTracking(new GetTrackingCommand($account, 'SE123456', 'stamps_com'));
        $this->assertSame(ShipmentLifecycleState::InTransit, $tracking->state);

        $validation = $orchestrator->validateAddress(new ValidateAddressCommand($account, $this->to));
        $this->assertTrue($validation->valid);
    }

    public function test_ups_adapter_oauth_rates_shipment_and_tracking(): void
    {
        Http::fake([
            'wwwcie.ups.com/security/v1/oauth/token' => Http::response(['access_token' => 'ups_tok_123']),
            'wwwcie.ups.com/api/rating/v2403/Shop' => Http::response([
                'RateResponse' => [
                    'RatedShipment' => [
                        ['Service' => ['Code' => '03'], 'TotalCharges' => ['MonetaryValue' => '11.40', 'CurrencyCode' => 'USD'], 'GuaranteedDelivery' => ['BusinessDaysInTransit' => '4']],
                        ['Service' => ['Code' => '01'], 'TotalCharges' => ['MonetaryValue' => '34.90', 'CurrencyCode' => 'USD'], 'GuaranteedDelivery' => ['BusinessDaysInTransit' => '1']],
                    ],
                ],
            ]),
            'wwwcie.ups.com/api/shipments/v2403/ship' => Http::response([
                'ShipmentResponse' => [
                    'ShipmentResults' => [
                        'ShipmentIdentificationNumber' => '1Z999AA10123456784',
                        'ShipmentCharges' => ['TotalCharges' => ['MonetaryValue' => '11.40', 'CurrencyCode' => 'USD']],
                    ],
                ],
            ]),
            'wwwcie.ups.com/api/track/v1/details/1Z999AA10123456784' => Http::response([
                'trackResponse' => [
                    'shipment' => [[
                        'package' => [[
                            'currentStatus' => ['type' => 'D'],
                            'activity' => [['status' => ['type' => 'D', 'description' => 'Delivered'], 'date' => '20261119']],
                        ]],
                    ]],
                ],
            ]),
        ]);

        $orchestrator = app(ShippingOrchestrator::class);
        $account = new ShippingAccountContextDto('t1', 'ups', ['client_id' => 'cid', 'client_secret' => 'csec', 'account_number' => 'A12345'], sandbox: true);

        $rates = $orchestrator->getRates(new GetRatesCommand($account, $this->from, $this->to, $this->parcel));
        $this->assertCount(2, $rates);
        $this->assertSame('Ground', $rates[0]->service);
        $this->assertSame(1140, $rates[0]->amount->amountMinor);

        $shipment = $orchestrator->createShipment(new CreateShipmentCommand($account, $this->from, $this->to, $this->parcel, 'ups_1', service: '03'));
        $this->assertSame('1Z999AA10123456784', $shipment->trackingNumber);

        $tracking = $orchestrator->getTracking(new GetTrackingCommand($account, '1Z999AA10123456784'));
        $this->assertSame(ShipmentLifecycleState::Delivered, $tracking->state);
    }

    public function test_fedex_adapter_oauth_rates_shipment_and_tracking(): void
    {
        Http::fake([
            'apis-sandbox.fedex.com/oauth/token' => Http::response(['access_token' => 'fdx_tok']),
            'apis-sandbox.fedex.com/rate/v1/rates/quotes' => Http::response([
                'output' => [
                    'rateReplyDetails' => [
                        ['serviceType' => 'FEDEX_GROUND', 'serviceName' => 'FedEx Ground', 'ratedShipmentDetails' => [['totalNetCharge' => 10.80, 'currency' => 'USD']]],
                    ],
                ],
            ]),
            'apis-sandbox.fedex.com/ship/v1/shipments' => Http::response([
                'output' => [
                    'transactionShipments' => [[
                        'masterTrackingNumber' => '794644790138',
                        'serviceName' => 'FedEx Ground',
                        'pieceResponses' => [['packageDocuments' => [['url' => 'https://labels.fedex.test/1.pdf']]]],
                    ]],
                ],
            ]),
            'apis-sandbox.fedex.com/track/v1/trackingnumbers' => Http::response([
                'output' => [
                    'completeTrackResults' => [[
                        'trackResults' => [[
                            'latestStatusDetail' => ['code' => 'OD'],
                            'scanEvents' => [['eventType' => 'OD', 'eventDescription' => 'On FedEx vehicle for delivery', 'date' => '2026-11-19T08:30:00Z']],
                        ]],
                    ]],
                ],
            ]),
        ]);

        $orchestrator = app(ShippingOrchestrator::class);
        $account = new ShippingAccountContextDto('t1', 'fedex', ['client_id' => 'fcid', 'client_secret' => 'fcsec', 'account_number' => '740561073'], sandbox: true);

        $rates = $orchestrator->getRates(new GetRatesCommand($account, $this->from, $this->to, $this->parcel));
        $this->assertCount(1, $rates);
        $this->assertSame('FedEx Ground', $rates[0]->service);
        $this->assertSame(1080, $rates[0]->amount->amountMinor);

        $shipment = $orchestrator->createShipment(new CreateShipmentCommand($account, $this->from, $this->to, $this->parcel, 'fdx_1', service: 'FEDEX_GROUND'));
        $this->assertSame('794644790138', $shipment->trackingNumber);

        $tracking = $orchestrator->getTracking(new GetTrackingCommand($account, '794644790138'));
        $this->assertSame(ShipmentLifecycleState::OutForDelivery, $tracking->state);
    }

    public function test_dhl_and_royalmail_adapters_rates_and_tracking(): void
    {
        Http::fake([
            'express.api.dhl.com/mydhlapi/test/rates' => Http::response([
                'products' => [
                    ['productCode' => 'P', 'productName' => 'EXPRESS WORLDWIDE', 'totalPrice' => [['price' => 42.50, 'priceCurrency' => 'EUR']], 'deliveryCapabilities' => ['totalTransitDays' => 2]],
                ],
            ]),
            'express.api.dhl.com/mydhlapi/test/shipments/1234567890/tracking' => Http::response([
                'shipments' => [['status' => 'OK', 'events' => [['typeCode' => 'OK', 'description' => 'Delivered']]]],
            ]),
            'api.parcel.royalmail.com/api/v1/rates' => Http::response([
                'rates' => [
                    ['serviceCode' => 'TPN24', 'serviceName' => 'Tracked 24', 'price' => 4.95, 'currency' => 'GBP', 'estimatedDeliveryDays' => 1],
                ],
            ]),
            'api.parcel.royalmail.com/api/v1/orders/RM123456789GB/tracking' => Http::response([
                'status' => 'DELIVERED',
                'events' => [['eventCode' => 'EVNDE', 'eventName' => 'Delivered']],
            ]),
        ]);

        $orchestrator = app(ShippingOrchestrator::class);

        $dhlAccount = new ShippingAccountContextDto('t1', 'dhl', ['api_key' => 'dhl_k', 'api_secret' => 'dhl_s', 'account_number' => '123456789'], sandbox: true);
        $dhlRates = $orchestrator->getRates(new GetRatesCommand($dhlAccount, $this->from, $this->to, $this->parcel));
        $this->assertCount(1, $dhlRates);
        $this->assertSame('EUR', $dhlRates[0]->amount->currency);
        $this->assertSame(4250, $dhlRates[0]->amount->amountMinor);
        $this->assertSame(ShipmentLifecycleState::Delivered, $orchestrator->getTracking(new GetTrackingCommand($dhlAccount, '1234567890'))->state);

        $rmAccount = new ShippingAccountContextDto('t1', 'royalmail', ['api_key' => 'rm_secret']);
        $rmRates = $orchestrator->getRates(new GetRatesCommand($rmAccount, $this->from, $this->to, $this->parcel));
        $this->assertCount(1, $rmRates);
        $this->assertSame('GBP', $rmRates[0]->amount->currency);
        $this->assertSame(495, $rmRates[0]->amount->amountMinor);
        $this->assertSame(ShipmentLifecycleState::Delivered, $orchestrator->getTracking(new GetTrackingCommand($rmAccount, 'RM123456789GB'))->state);
    }
}
