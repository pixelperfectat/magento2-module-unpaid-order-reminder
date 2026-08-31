<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Cron;

use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderRunnerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class SendUnpaidOrderReminders
{
    /**
     * @param ReminderRunnerInterface $runner
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ReminderRunnerInterface $runner,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute the reminder run, logging failures but not re-throwing to avoid retry storms.
     *
     * A thrown exception makes Magento mark the job errored and run it again. This job sends mail,
     * so a retry storm is worse than a missed day; the failure is logged and swallowed.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $result = $this->runner->run();
        } catch (Throwable $e) {
            $this->logger->error(
                'UnpaidOrderReminder: the run failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return;
        }

        $this->logger->info(sprintf(
            'UnpaidOrderReminder: %d reminder(s) sent, %d skipped.',
            $result->getSentCount(),
            $result->getSkippedCount()
        ));
    }
}
