<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Service;

use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRunResultInterface;

/**
 * Selects unpaid orders, resolves each one's payment instructions, and sends the reminder.
 */
interface ReminderRunnerInterface
{
    /**
     * Selects every eligible order and sends one reminder for each.
     *
     * Never throws for one bad order: a failure is recorded as a skip and the run continues, so one
     * unreachable gateway cannot hide the rest of the population.
     *
     * @param bool $dryRun decide everything, change nothing
     * @return ReminderRunResultInterface
     */
    public function run(bool $dryRun = false): ReminderRunResultInterface;
}
