<?php

namespace App\Services;

use App\Factories\CarrierRateFactory;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingQuote;
use App\Services\Shipping\CarrierRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Liberu\Shipping\Contracts\Contracts\AddressValidationContract;
use Liberu\Shipping\Contracts\DTOs\AddressDto;
use Liberu\Shipping\Contracts\DTOs\CancelShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\CancelShipmentResultDto;
use Liberu\Shipping\Contracts\DTOs\CreateShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\GetTrackingCommand;
use Liberu\Shipping\Contracts\DTOs\ParcelDto;
use Liberu\Shipping\Contracts\DTOs\ShipmentResultDto;
use Liberu\Shipping\Contracts\DTOs\ShippingAccountContextDto;
use Liberu\Shipping\Contracts\DTOs\ShippingCapabilityDescriptorDto;
use Liberu\Shipping\Contracts\DTOs\TrackingResultDto;
use Liberu\Shipping\Contracts\DTOs\ValidateAddressCommand;
use Liberu\Shipping\Core\Services\ShippingOrchestrator;
use RuntimeException;

class ShippingService
{
    public function __construct(private readonly ?ShippingOrchestrator $orchestrator = null) {}

    /**
     * Fetch live rates and PERSIST each as a ShippingQuote scoped to this session, so
     * the buyer can later select one by id and the server bills its stored amount.
     * Returns the persisted quotes (empty when no carrier is configured/reachable).
     *
     * @return Collection<int, ShippingQuote>
     */
    public function quoteLiveRates($cart, array $to, string $sessionId, ?int $userId = null): Collection
    {
        $rates = $this->getLiveRates($cart, $to);
        $expiresAt = now()->addMinutes((int) config('shipping.quote_ttl', 30));

        return collect($rates)->map(fn (CarrierRate $rate) => ShippingQuote::create([
            'session_id' => $sessionId,
            'user_id' => $userId,
            'carrier' => $rate->carrier,
            'service' => $rate->service,
            'amount' => $rate->amount,
            'currency' => $rate->currency,
            'delivery_days' => $rate->deliveryDays,
            'rate_id' => $rate->rateId,
            'expires_at' => $expiresAt,
        ]));
    }

    /**
     * Resolve a quote the caller is allowed to use: it must belong to this session (or,
     * for a logged-in buyer, to them) and not be expired. Returns null otherwise — the
     * caller rejects checkout rather than trusting a foreign, stale, or client-fabricated
     * rate.
     */
    public function resolveQuote(int $quoteId, string $sessionId, ?int $userId = null): ?ShippingQuote
    {
        return ShippingQuote::query()
            ->where('id', $quoteId)
            ->where(function ($query) use ($sessionId, $userId) {
                $query->where('session_id', $sessionId);
                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->active()
            ->first();
    }

    /**
     * Live carrier rates for a cart shipping to $to. Returns [] when no live-rate
     * carrier is configured or the carrier is unreachable — the caller then falls
     * back to the flat DB methods from getAvailableShippingMethods().
     *
     * @param  array  $to  destination address (name/street1/city/state/zip/country)
     * @param  array|null  $from  origin; defaults to config('shipping.origin')
     * @return CarrierRate[]
     */
    public function getLiveRates($cart, array $to, ?array $from = null): array
    {
        $carrier = CarrierRateFactory::create();
        if ($carrier === null) {
            return [];
        }

        $parcel = ['weight' => $this->calculateTotalWeight($cart)];

        return $carrier->getRates($parcel, $from ?? (array) config('shipping.origin'), $to);
    }

    /**
     * Purchase a shipping label / create a shipment through the configured (or specified)
     * shipping provider via liberusoftware/shipping-core.
     *
     * @param  array<string, mixed>  $parcel
     * @param  array<string, mixed>  $to
     * @param  array<string, mixed>|null  $from
     * @param  array<string, mixed>  $metadata
     */
    public function createShipment(
        array $parcel,
        array $to,
        ?array $from = null,
        ?string $provider = null,
        ?string $rateId = null,
        ?string $carrier = null,
        ?string $service = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
        string $tenantId = 'default',
    ): ShipmentResultDto {
        $providerKey = $this->resolveProviderKey($provider);

        return $this->orchestrator()->createShipment(new CreateShipmentCommand(
            account: $this->accountContext($providerKey, $tenantId),
            from: AddressDto::fromArray($from ?? (array) config('shipping.origin')),
            to: AddressDto::fromArray($to),
            parcel: ParcelDto::fromArray($parcel, (string) config('shipping.weight_unit', 'oz')),
            idempotencyKey: $idempotencyKey ?? uniqid('ecom_ship_', true),
            rateId: $rateId,
            carrier: $carrier,
            service: $service,
            metadata: $metadata,
        ));
    }

    /**
     * Cancel / void / refund a shipment with the configured (or specified) shipping provider.
     */
    public function cancelShipment(
        string $providerShipmentId,
        ?string $provider = null,
        ?string $reason = null,
        string $tenantId = 'default',
    ): CancelShipmentResultDto {
        $providerKey = $this->resolveProviderKey($provider);

        return $this->orchestrator()->cancelShipment(new CancelShipmentCommand(
            account: $this->accountContext($providerKey, $tenantId),
            providerShipmentId: $providerShipmentId,
            idempotencyKey: 'cancel_'.$providerShipmentId,
            reason: $reason,
        ));
    }

    /**
     * Fetch live tracking status and events for a tracking number through the configured
     * (or specified) shipping provider.
     */
    public function trackShipment(
        string $trackingNumber,
        ?string $carrier = null,
        ?string $provider = null,
        string $tenantId = 'default',
    ): TrackingResultDto {
        $providerKey = $this->resolveProviderKey($provider);

        return $this->orchestrator()->getTracking(new GetTrackingCommand(
            account: $this->accountContext($providerKey, $tenantId),
            trackingNumber: $trackingNumber,
            carrier: $carrier,
        ));
    }

    /**
     * Return capability descriptors for all registered shipping provider adapters.
     *
     * @return array<string, ShippingCapabilityDescriptorDto>
     */
    public function availableShippingProviders(): array
    {
        return $this->orchestrator()->availableProviders();
    }

    /**
     * Build a ShippingAccountContextDto for a provider from host configuration.
     */
    public function accountContext(string $providerKey, string $tenantId = 'default'): ShippingAccountContextDto
    {
        $key = strtolower(trim($providerKey));
        $providerConfig = (array) config("shipping.{$key}", []);

        return new ShippingAccountContextDto(
            tenantId: $tenantId,
            providerKey: $key,
            credentials: $providerConfig,
            sandbox: (bool) ($providerConfig['sandbox'] ?? false),
            apiVersion: isset($providerConfig['api_version']) ? (string) $providerConfig['api_version'] : null,
            timeoutSeconds: (int) config('shipping.timeout', 15),
        );
    }

    public function getAvailableShippingMethods($cart = null, $address = null)
    {
        // Only offer active methods; a deactivated method must never be selectable.
        $availableMethods = ShippingMethod::where('is_active', true)->get();

        if (! $cart || ! $address) {
            return $availableMethods;
        }

        // Filter methods based on package weight, dimensions, and destination
        return $availableMethods->filter(function ($method) use ($cart, $address) {
            return $this->isMethodAvailable($method, $cart, $address);
        });
    }

    public function calculateShippingCost(ShippingMethod $method, $cart, $address)
    {
        // Implement logic to calculate shipping cost based on the method, cart contents, and address
        $baseRate = $method->base_rate;
        $weightRate = $this->calculateWeightRate($method, $cart);
        $distanceRate = $this->calculateDistanceRate($method, $address);

        // Round to cents: float weight math otherwise leaks e.g. 0.30000000000000004.
        return round($baseRate + $weightRate + $distanceRate, 2);
    }

    public function verifyAddress($address)
    {
        $configuredProvider = config('shipping.carrier');
        if (is_string($configuredProvider) && $configuredProvider !== '' && is_array($address)) {
            $registry = $this->orchestrator()->registry();
            if ($registry->has($configuredProvider)
                && $registry->resolve($configuredProvider) instanceof AddressValidationContract) {
                $result = $this->orchestrator()->validateAddress(new ValidateAddressCommand(
                    account: $this->accountContext($configuredProvider),
                    address: AddressDto::fromArray($address),
                ));

                return [
                    'valid' => $result->valid,
                    'provider' => $result->providerKey,
                    'normalized' => $result->normalizedAddress?->toArray(),
                    'messages' => $result->messages,
                ];
            }
        }

        $response = Http::get('https://api.address-verifier.com', [
            'address' => $address,
            'api_key' => config('services.address_verifier.api_key'),
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    private function orchestrator(): ShippingOrchestrator
    {
        return $this->orchestrator ?? app(ShippingOrchestrator::class);
    }

    private function resolveProviderKey(?string $provider): string
    {
        $resolved = $provider ?? config('shipping.carrier');
        if (! is_string($resolved) || trim($resolved) === '') {
            throw new RuntimeException('No shipping provider is configured.');
        }

        return strtolower(trim($resolved));
    }

    private function isMethodAvailable($method, $cart, $address)
    {
        // Implement logic to check if the shipping method is available for the given cart and address
        // This is a simplified example. You would need to implement more complex logic based on your specific requirements.
        $totalWeight = $this->calculateTotalWeight($cart);
        $maxWeight = $method->max_weight;

        // Null max_weight = no weight limit. Without this guard, `$w <= null`
        // evaluates as `$w <= 0` in PHP, wrongly rejecting unlimited methods.
        return $maxWeight === null || $totalWeight <= $maxWeight;
    }

    private function calculateWeightRate($method, $cart)
    {
        $totalWeight = $this->calculateTotalWeight($cart);

        return $totalWeight * $method->weight_rate;
    }

    private function calculateDistanceRate($method, $address)
    {
        // Implement logic to calculate distance-based rate
        // This is a placeholder. You would need to integrate with a distance calculation service.
        return 0;
    }

    private function calculateTotalWeight($cart)
    {
        // The storefront cart carries each line's weight. A cart without it must be
        // keyed by product id, and the weights are looked up in one query.
        $lookupIds = [];
        foreach ($cart as $productId => $item) {
            if (! isset($item['weight'])) {
                $lookupIds[] = $productId;
            }
        }
        $weights = empty($lookupIds)
            ? collect()
            : Product::whereIn('id', $lookupIds)->pluck('weight', 'id');

        $total = 0.0;
        foreach ($cart as $productId => $item) {
            $weight = (float) ($item['weight'] ?? $weights[$productId] ?? 0);
            $total += $weight * $item['quantity'];
        }

        return $total;
    }

    public function calculateDropShippingCost(ShippingMethod $method, $cart, $address)
    {
        // Add a small premium for drop shipping
        $standardCost = $this->calculateShippingCost($method, $cart, $address);
        $dropShippingPremium = config('shipping.drop_shipping_premium', 2.00);

        return $standardCost + $dropShippingPremium;
    }
}
