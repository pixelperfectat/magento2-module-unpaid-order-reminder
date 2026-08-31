<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Service;

use Magento\Sales\Api\Data\OrderInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\PaymentInstructionsInterface;

/**
 * Produces the instructions a shopper needs to complete an unpaid order.
 *
 * This is the only thing that differs per payment method. Everything else about a reminder - which
 * orders qualify, when to send, what to record - is method-agnostic.
 */
interface PaymentInstructionsProviderInterface
{
    /**
     * Returns the payment instructions for an order.
     *
     * Returns null when instructions cannot be produced: the provider does not recognise the order,
     * the payment has no reference yet, or a remote lookup failed.
     *
     * A null is never rendered as an empty mail. The caller abandons the send and retries later.
     *
     * @param OrderInterface $order
     * @return PaymentInstructionsInterface|null
     */
    public function forOrder(OrderInterface $order): ?PaymentInstructionsInterface;
}
