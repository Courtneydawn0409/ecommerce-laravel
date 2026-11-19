<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways;

use App\Interfaces\PaymentGatewayInterface;
use App\Support\Money;
use Exception;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\MoneyAmount;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;
use Liberu\Payment\Core\Services\PaymentOrchestrator;

class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly PaymentOrchestrator $orchestrator) {}

    public function processPayment(float $amount, array $paymentDetails): array
    {
        try {
            $token = $paymentDetails['token'] ?? $paymentDetails['payment_method'] ?? null;
            $result = $this->orchestrator->createIntent(new CreatePaymentIntentCommand(
                account: new MerchantAccountContextDto(
                    tenantId: (string) ($paymentDetails['tenant_id'] ?? 'default'),
                    providerKey: 'stripe',
                    credentials: ['secret_key' => (string) config('services.stripe.secret')],
                    apiVersion: config('services.stripe.api_version'),
                ),
                amount: new MoneyAmount((int) round($amount * 100), Money::currency()),
                idempotencyKey: (string) ($paymentDetails['idempotency_key'] ?? uniqid('ecom_stripe_', true)),
                paymentMethodToken: is_string($token) ? $token : null,
                description: 'Ecommerce payment transaction',
            ));

            if (in_array($result->state, [PaymentLifecycleState::Captured, PaymentLifecycleState::Authorized], true)) {
                return ['success' => true, 'transaction_id' => $result->providerIntentId];
            }

            return ['success' => false, 'error' => 'Payment requires action or failed: '.$result->state->value];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processSubscription(string $planId, array $subscriptionDetails): array
    {
        return ['success' => false, 'error' => 'Stripe subscriptions are managed through liberusoftware/payment-core.'];
    }

    public function refundPayment(string $transactionId, float $amount): array
    {
        try {
            $result = $this->orchestrator->refund(new RefundPaymentCommand(
                account: new MerchantAccountContextDto(
                    tenantId: 'default',
                    providerKey: 'stripe',
                    credentials: ['secret_key' => (string) config('services.stripe.secret')],
                ),
                providerReference: $transactionId,
                amount: new MoneyAmount((int) round($amount * 100), Money::currency()),
                idempotencyKey: 'refund_'.$transactionId.'_'.(int) round($amount * 100),
            ));

            return ['success' => true, 'refund_id' => $result->providerRefundId];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
