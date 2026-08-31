<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Data;

/**
 * How the reminders performed. Four disjoint groups, each with a count and a value.
 *
 * "reminded" is the whole population; the other three partition it by what the order did next.
 */
interface ReminderEfficacyInterface
{
    /**
     * Get how many orders were reminded.
     *
     * @return int
     */
    public function getRemindedCount(): int;

    /**
     * Get the combined grand total of every reminded order.
     *
     * @return float
     */
    public function getRemindedValue(): float;

    /**
     * Get how many orders left pending payment for a paid state, after the reminder was sent.
     *
     * An order already paid when the reminder was written is not credited to it.
     *
     * @return int
     */
    public function getPaidCount(): int;

    /**
     * Get the combined grand total of the orders paid after their reminder.
     *
     * @return float
     */
    public function getPaidValue(): float;

    /**
     * Get how many orders are still pending payment, within their payment window.
     *
     * @return int
     */
    public function getStillUnpaidCount(): int;

    /**
     * Get the combined grand total of the orders still awaiting payment.
     *
     * @return float
     */
    public function getStillUnpaidValue(): float;

    /**
     * Get how many orders are cancelled, or pending payment past their expiry.
     *
     * @return int
     */
    public function getExpiredCount(): int;

    /**
     * Get the combined grand total of the expired unpaid orders.
     *
     * @return float
     */
    public function getExpiredValue(): float;
}
