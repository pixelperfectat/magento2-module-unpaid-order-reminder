<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Console\Command;

use Magento\Framework\Console\Cli;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRunResultInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderRunnerInterface;
use PixelPerfect\UnpaidOrderReminder\Console\Command\SendRemindersCommand;
use Symfony\Component\Console\Tester\CommandTester;

class SendRemindersCommandTest extends TestCase
{
    public function testPrintsWhatWasSentAndSucceeds(): void
    {
        $tester = $this->executeCommand($this->result(
            [['order_id' => 900, 'increment_id' => '000000900', 'payment_method' => 'banktransfer', 'expires_at' => '2099-01-01 00:00:00', 'reason' => null]],
            []
        ));

        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('000000900', $tester->getDisplay());
        $this->assertStringContainsString('1 reminder(s) sent, 0 skipped.', $tester->getDisplay());
    }

    public function testPassesTheDryRunOptionThrough(): void
    {
        $runner = $this->createMock(ReminderRunnerInterface::class);
        $runner->expects($this->once())->method('run')->with(true)->willReturn($this->result([], []));

        $tester = new CommandTester(new SendRemindersCommand($runner));
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('would be sent', $tester->getDisplay());
    }

    /**
     * An operator must be able to see a skip in a pipeline exit code, not only in the output.
     */
    public function testExitsNonZeroWhenAnythingWasSkipped(): void
    {
        $tester = $this->executeCommand($this->result(
            [],
            [['order_id' => 901, 'increment_id' => '000000901', 'payment_method' => 'banktransfer', 'expires_at' => null, 'reason' => 'no_instructions']]
        ));

        $this->assertSame(Cli::RETURN_FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('no_instructions', $tester->getDisplay());
    }

    public function testReportsAnEmptyRunPlainly(): void
    {
        $tester = $this->executeCommand($this->result([], []));

        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No order qualifies', $tester->getDisplay());
    }

    /**
     * @param ReminderRunResultInterface $result
     * @return CommandTester
     */
    private function executeCommand(ReminderRunResultInterface $result): CommandTester
    {
        $runner = $this->createMock(ReminderRunnerInterface::class);
        $runner->method('run')->willReturn($result);

        $tester = new CommandTester(new SendRemindersCommand($runner));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @param array<int, array<string, mixed>> $sent
     * @param array<int, array<string, mixed>> $skipped
     * @return ReminderRunResultInterface
     */
    private function result(array $sent, array $skipped): ReminderRunResultInterface
    {
        $result = $this->createMock(ReminderRunResultInterface::class);
        $result->method('getSent')->willReturn($sent);
        $result->method('getSkipped')->willReturn($skipped);
        $result->method('getSentCount')->willReturn(count($sent));
        $result->method('getSkippedCount')->willReturn(count($skipped));

        return $result;
    }
}
