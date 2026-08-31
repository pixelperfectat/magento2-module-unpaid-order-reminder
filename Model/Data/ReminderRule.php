<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model\Data;

use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRuleInterface;

/**
 * One configured rule: for this payment method, wait this many days, then send this template.
 */
class ReminderRule implements ReminderRuleInterface
{
    /**
     * @param string $paymentMethod
     * @param int $delayDays
     * @param string $emailTemplate
     */
    public function __construct(
        private readonly string $paymentMethod,
        private readonly int $delayDays,
        private readonly string $emailTemplate
    ) {
    }

    /**
     * Get the payment method code.
     *
     * @return string
     */
    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    /**
     * Get the delay in days.
     *
     * @return int
     */
    public function getDelayDays(): int
    {
        return $this->delayDays;
    }

    /**
     * Get the email template identifier.
     *
     * @return string
     */
    public function getEmailTemplate(): string
    {
        return $this->emailTemplate;
    }
}
