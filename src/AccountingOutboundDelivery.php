<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use Throwable;

final class AccountingOutboundDelivery implements OutboundDeliveryInterface
{
    public function __construct(private OutboundDeliveryInterface $next, private TurnAccountingService $accounting) {}
    public function deliverOutboundEvent(OutboundEvent $event): void
    {
        $this->accounting->observe($event);
        $this->next->deliverOutboundEvent($event);
    }
    public function reportOutboundDeliveryFailure(OutboundEvent $event, Throwable $error): void { $this->next->reportOutboundDeliveryFailure($event, $error); }
}
