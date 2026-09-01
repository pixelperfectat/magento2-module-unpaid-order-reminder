<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
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

public function testPassesTheDryRunOptionThroughAndSayWouldBeSent(): void
    {
        $runner = $this->createMock(ReminderRunnerInterface::class);
        $runner->expects($this->once())->method('run')->with(true)->willReturn($this->result(
            [['order_id' => 900, 'increment_id' => '000000900', 'payment_method' => 'banktransfer', 'expires_at' => '2099-01-01 00:00:00', 'reason' => null]],
            []
        ));

        $tester = new CommandTester(new SendRemindersCommand($runner, $this->state()));
        $tester->execute(['--dry-run' => true]);

        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('would be sent', $tester->getDisplay());
        $this->assertStringNotContainsString('reminder(s) sent,', $tester->getDisplay());
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
     * The command reaches Emulation::startEnvironmentEmulation() through the runner, which throws
     * "Area code is not set" unless an area is set first. A test that only asserts setAreaCode() was
     * called, without pinning it before the runner, would not catch a regression that moves the call
     * after the send.
     */
    public function testSetsTheAreaCodeBeforeRunningTheJob(): void
    {
        $calls = [];

        $state = $this->createMock(State::class);
        $state->expects($this->once())
            ->method('setAreaCode')
            ->with(Area::AREA_CRONTAB)
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'setAreaCode';
            });

        $result = $this->result([], []);
        $runner = $this->createMock(ReminderRunnerInterface::class);
        $runner->method('run')->willReturnCallback(function () use (&$calls, $result): ReminderRunResultInterface {
            $calls[] = 'run';

            return $result;
        });

        $tester = new CommandTester(new SendRemindersCommand($runner, $state));
        $tester->execute([]);

        $this->assertSame(['setAreaCode', 'run'], $calls);
    }

    /**
     * Some contexts already have an area set (e.g. a caller running under one) and setAreaCode()
     * throws a second time; the command must tolerate that rather than failing the run.
     */
    public function testToleratesAnAlreadySetAreaCode(): void
    {
        $state = $this->createMock(State::class);
        $state->method('setAreaCode')->willThrowException(new LocalizedException(__('Area code is already set')));

        $runner = $this->createMock(ReminderRunnerInterface::class);
        $runner->method('run')->willReturn($this->result([], []));

        $tester = new CommandTester(new SendRemindersCommand($runner, $state));
        $tester->execute([]);

        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
    }

    /**
     * @param ReminderRunResultInterface $result
     * @return CommandTester
     */
    private function executeCommand(ReminderRunResultInterface $result): CommandTester
    {
        $runner = $this->createMock(ReminderRunnerInterface::class);
        $runner->method('run')->willReturn($result);

        $tester = new CommandTester(new SendRemindersCommand($runner, $this->state()));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @return State&MockObject
     */
    private function state(): State
    {
        return $this->createMock(State::class);
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
