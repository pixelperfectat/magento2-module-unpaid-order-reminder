<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model\Data;

use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRunResultInterface;

/**
 * What one run did.
 */
class ReminderRunResult implements ReminderRunResultInterface
{
    /**
     * Constructor.
     *
     * @param array<int, array<string, mixed>> $sent
     * @param array<int, array<string, mixed>> $skipped
     */
    public function __construct(
        private readonly array $sent = [],
        private readonly array $skipped = []
    ) {
    }

    /**
     * Get the orders a reminder was sent for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSent(): array
    {
        return $this->sent;
    }

    /**
     * Get the orders that were skipped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /**
     * Get how many reminders were sent.
     *
     * @return int
     */
    public function getSentCount(): int
    {
        return count($this->sent);
    }

    /**
     * Get how many orders were skipped.
     *
     * @return int
     */
    public function getSkippedCount(): int
    {
        return count($this->skipped);
    }
}
