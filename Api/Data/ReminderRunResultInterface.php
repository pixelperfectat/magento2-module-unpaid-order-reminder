<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Data;

/**
 * What one run did. The console command prints it; the cron logs a summary of it.
 */
interface ReminderRunResultInterface
{
    /**
     * Get the orders a reminder was sent for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSent(): array;

    /**
     * Get the orders that were skipped.
     *
     * @return array<int, array<string, mixed>> each entry carries a 'reason'
     */
    public function getSkipped(): array;

    /**
     * Get how many reminders were sent.
     *
     * @return int
     */
    public function getSentCount(): int;

    /**
     * Get how many orders were skipped.
     *
     * @return int
     */
    public function getSkippedCount(): int;
}
