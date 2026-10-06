<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use Throwable;

interface OutboundDeliveryInterface
{
    public function deliverOutboundEvent(OutboundEvent $event): void;
    public function reportOutboundDeliveryFailure(OutboundEvent $event, Throwable $error): void;
}
