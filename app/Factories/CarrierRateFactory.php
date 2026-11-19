<?php

declare(strict_types=1);

namespace App\Factories;

use App\Interfaces\CarrierRateInterface;
use App\Services\Shipping\EasyPostCarrier;
use App\Services\Shipping\OrchestratedCarrier;
use Liberu\Shipping\Contracts\Contracts\ShippingProviderRegistryContract;
use Liberu\Shipping\Core\Services\ShippingOrchestrator;

class CarrierRateFactory
{
    /**
     * Resolve the configured live-rate shipping provider, or null when none is
     * configured (the store then falls back to its flat DB shipping methods).
     *
     * Any provider registered with ShippingProviderRegistryContract (such as
     * easypost, shippo, shipengine, ups, fedex, dhl, royalmail, or custom
     * shipping-* modules) is resolved automatically.
     */
    public static function create(?string $carrier = null): ?CarrierRateInterface
    {
        $carrier ??= config('shipping.carrier');
        if (! is_string($carrier) || trim($carrier) === '') {
            return null;
        }

        $key = strtolower(trim($carrier));
        if ($key === 'easypost') {
            return app(EasyPostCarrier::class);
        }

        if (app()->bound(ShippingProviderRegistryContract::class)
            && app(ShippingProviderRegistryContract::class)->has($key)) {
            return new OrchestratedCarrier($key, app(ShippingOrchestrator::class));
        }

        return null;
    }
}
