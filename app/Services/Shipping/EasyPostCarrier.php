<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use Liberu\Shipping\Core\Services\ShippingOrchestrator;

/**
 * Live rates via EasyPost, delegated to liberusoftware/shipping-easypost
 * through liberusoftware/shipping-core's ShippingOrchestrator.
 */
class EasyPostCarrier extends OrchestratedCarrier
{
    public function __construct(?ShippingOrchestrator $orchestrator = null)
    {
        parent::__construct('easypost', $orchestrator);
    }
}
