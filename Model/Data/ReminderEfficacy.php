<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model\Data;

use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderEfficacyInterface;

/**
 * How the reminders performed.
 */
class ReminderEfficacy implements ReminderEfficacyInterface
{
    /**
     * Constructor.
     *
     * @param int $remindedCount
     * @param float $remindedValue
     * @param int $paidCount
     * @param float $paidValue
     * @param int $stillUnpaidCount
     * @param float $stillUnpaidValue
     * @param int $expiredCount
     * @param float $expiredValue
     */
    public function __construct(
        private readonly int $remindedCount = 0,
        private readonly float $remindedValue = 0.0,
        private readonly int $paidCount = 0,
        private readonly float $paidValue = 0.0,
        private readonly int $stillUnpaidCount = 0,
        private readonly float $stillUnpaidValue = 0.0,
        private readonly int $expiredCount = 0,
        private readonly float $expiredValue = 0.0
    ) {
    }

    /**
     * Get how many orders were reminded.
     *
     * @return int
     */
    public function getRemindedCount(): int
    {
        return $this->remindedCount;
    }

    /**
     * Get the combined grand total of every reminded order.
     *
     * @return float
     */
    public function getRemindedValue(): float
    {
        return $this->remindedValue;
    }

    /**
     * Get how many orders left pending payment for a paid state, after the reminder was sent.
     *
     * @return int
     */
    public function getPaidCount(): int
    {
        return $this->paidCount;
    }

    /**
     * Get the combined grand total of the orders paid after their reminder.
     *
     * @return float
     */
    public function getPaidValue(): float
    {
        return $this->paidValue;
    }

    /**
     * Get how many orders are still pending payment, within their payment window.
     *
     * @return int
     */
    public function getStillUnpaidCount(): int
    {
        return $this->stillUnpaidCount;
    }

    /**
     * Get the combined grand total of the orders still awaiting payment.
     *
     * @return float
     */
    public function getStillUnpaidValue(): float
    {
        return $this->stillUnpaidValue;
    }

    /**
     * Get how many orders are cancelled, or pending payment past their expiry.
     *
     * @return int
     */
    public function getExpiredCount(): int
    {
        return $this->expiredCount;
    }

    /**
     * Get the combined grand total of the expired unpaid orders.
     *
     * @return float
     */
    public function getExpiredValue(): float
    {
        return $this->expiredValue;
    }
}
