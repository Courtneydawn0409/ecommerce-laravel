<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Interfaces\CarrierRateInterface;
use Liberu\Shipping\Contracts\DTOs\AddressDto;
use Liberu\Shipping\Contracts\DTOs\GetRatesCommand;
use Liberu\Shipping\Contracts\DTOs\ParcelDto;
use Liberu\Shipping\Contracts\DTOs\ShippingAccountContextDto;
use Liberu\Shipping\Contracts\DTOs\ShippingRateDto;
use Liberu\Shipping\Core\Services\ShippingOrchestrator;
use Throwable;

/**
 * Bridges the host's CarrierRateInterface to any provider registered in
 * liberusoftware/shipping-core.
 *
 * Rating never blocks checkout: an unconfigured provider, non-2xx response,
 * or network error returns [] so the caller falls back to flat DB methods.
 */
class OrchestratedCarrier implements CarrierRateInterface
{
    public function __construct(
        private readonly string $providerKey,
        private readonly ?ShippingOrchestrator $orchestrator = null,
    ) {}

    public function providerKey(): string
    {
        return $this->providerKey;
    }

    public function getRates(array $parcel, array $from, array $to): array
    {
        try {
            $orchestrator = $this->orchestrator ?? app(ShippingOrchestrator::class);
            $providerConfig = (array) config("shipping.{$this->providerKey}", []);

            $rates = $orchestrator->getRates(new GetRatesCommand(
                account: new ShippingAccountContextDto(
                    tenantId: 'default',
                    providerKey: $this->providerKey,
                    credentials: $providerConfig,
                    sandbox: (bool) ($providerConfig['sandbox'] ?? false),
                    apiVersion: isset($providerConfig['api_version']) ? (string) $providerConfig['api_version'] : null,
                    timeoutSeconds: (int) config('shipping.timeout', 15),
                ),
                from: AddressDto::fromArray($from),
                to: AddressDto::fromArray($to),
                parcel: ParcelDto::fromArray($parcel, (string) config('shipping.weight_unit', 'oz')),
            ));

            return array_map(
                static fn (ShippingRateDto $rate): CarrierRate => new CarrierRate(
                    carrier: $rate->carrier,
                    service: $rate->service,
                    amount: $rate->amount->toMajor(),
                    currency: $rate->amount->currency,
                    deliveryDays: $rate->deliveryDays,
                    rateId: $rate->rateId,
                ),
                $rates,
            );
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
