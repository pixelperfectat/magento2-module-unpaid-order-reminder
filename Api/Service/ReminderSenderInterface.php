<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Service;

use Magento\Framework\Exception\MailException;
use Magento\Sales\Api\Data\OrderInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\PaymentInstructionsInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRuleInterface;

/**
 * Sends one unpaid-order reminder email.
 */
interface ReminderSenderInterface
{
    /**
     * Send one reminder.
     *
     * Returns normally only when the transport accepted the message, so the caller may treat a
     * return as permission to write the log row.
     *
     * @param OrderInterface $order
     * @param PaymentInstructionsInterface $instructions
     * @param ReminderRuleInterface $rule
     * @return void
     * @throws MailException
     */
    public function send(
        OrderInterface $order,
        PaymentInstructionsInterface $instructions,
        ReminderRuleInterface $rule
    ): void;
}
