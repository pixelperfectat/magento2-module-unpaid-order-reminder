<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Data;

/**
 * One configured rule: for this payment method, wait this many days, then send this template.
 */
interface ReminderRuleInterface
{
    /**
     * Get the payment method code.
     *
     * @return string
     */
    public function getPaymentMethod(): string;

    /**
     * Get the delay in days.
     *
     * @return int whole days, always at least 1
     */
    public function getDelayDays(): int;

    /**
     * Get the email template identifier.
     *
     * @return string email template identifier
     */
    public function getEmailTemplate(): string;
}
