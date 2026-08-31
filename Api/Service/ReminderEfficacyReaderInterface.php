<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Service;

use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderEfficacyInterface;

/**
 * Answers how the reminders performed.
 */
interface ReminderEfficacyReaderInterface
{
    /**
     * Reads the outcome of every reminder sent since the given moment.
     *
     * This only ever reads. Nothing in this module observes a payment or writes to sales_order; the
     * order's own state is the source of truth for what happened after the mail.
     *
     * @param string|null $sinceGmt 'Y-m-d H:i:s' UTC, or null for the whole log
     * @return ReminderEfficacyInterface
     */
    public function read(?string $sinceGmt = null): ReminderEfficacyInterface;
}
