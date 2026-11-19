<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways;

use App\Interfaces\PaymentGatewayInterface;
use App\Support\Money;
use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\MoneyAmount;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Core\Services\PaymentOrchestrator;
use Throwable;

class PayPalGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly PaymentOrchestrator $orchestrator) {}

    public function processPayment(float $amount, array $paymentDetails): array
    {
        $orderId = $paymentDetails['payment_id'] ?? null;
        if (empty($orderId)) {
            return ['success' => false, 'error' => 'Missing PayPal order id.'];
        }

        try {
            $result = $this->orchestrator->capture(new CapturePaymentCommand(
                account: new MerchantAccountContextDto(
                    tenantId: (string) ($paymentDetails['tenant_id'] ?? 'default'),
                    providerKey: 'paypal',
                    credentials: [
                        'client_id' => (string) config('services.paypal.client_id'),
                        'secret' => (string) config('services.paypal.secret'),
                    ],
                ),
                providerIntentId: (string) $orderId,
                idempotencyKey: 'paypal_cap_'.$orderId,
                amount: new MoneyAmount((int) round($amount * 100), Money::currency()),
            ));

            return [
                'success' => true,
                'transaction_id' => $result->providerCaptureId,
                'status' => 'COMPLETED',
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function refundPayment(string $transactionId, float $amount): array
    {
        try {
            $result = $this->orchestrator->refund(new RefundPaymentCommand(
                account: new MerchantAccountContextDto(
                    tenantId: 'default',
                    providerKey: 'paypal',
                    credentials: [
                        'client_id' => (string) config('services.paypal.client_id'),
                        'secret' => (string) config('services.paypal.secret'),
                    ],
                ),
                providerReference: $transactionId,
                amount: new MoneyAmount((int) round($amount * 100), Money::currency()),
                idempotencyKey: 'paypal_ref_'.$transactionId.'_'.(int) round($amount * 100),
            ));

            return ['success' => true, 'refund_id' => $result->providerRefundId, 'status' => 'COMPLETED'];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processSubscription(string $planId, array $subscriptionDetails): array
    {
        return ['success' => false, 'error' => 'PayPal subscriptions are managed through liberusoftware/payment-core.'];
    }
}
