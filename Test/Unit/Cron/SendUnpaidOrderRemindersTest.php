<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRunResultInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderRunnerInterface;
use PixelPerfect\UnpaidOrderReminder\Cron\SendUnpaidOrderReminders;

class SendUnpaidOrderRemindersTest extends TestCase
{
    public function testRunsTheReminderRunnerForReal(): void
    {
        $runner = $this->createMock(ReminderRunnerInterface::class);
        $runner->expects($this->once())->method('run')->with(false)->willReturn($this->result(2, 1));

        (new SendUnpaidOrderReminders($runner, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testLogsASummaryOfTheRun(): void
    {
        $runner = $this->createMock(ReminderRunnerInterface::class);
        $runner->method('run')->willReturn($this->result(2, 1));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('UnpaidOrderReminder: 2 reminder(s) sent, 1 skipped.');

        (new SendUnpaidOrderReminders($runner, $logger))->execute();
    }

    /**
     * Magento's cron marks a job errored and retries it. A reminder run is not idempotent in a way
     * that survives a retry storm, so a failure is logged and swallowed.
     */
    public function testLogsAndSwallowsAFailureSoCronDoesNotRetry(): void
    {
        $runner = $this->createMock(ReminderRunnerInterface::class);
        $runner->method('run')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        (new SendUnpaidOrderReminders($runner, $logger))->execute();
    }

    private function result(int $sent, int $skipped): ReminderRunResultInterface
    {
        $result = $this->createMock(ReminderRunResultInterface::class);
        $result->method('getSentCount')->willReturn($sent);
        $result->method('getSkippedCount')->willReturn($skipped);

        return $result;
    }
}
