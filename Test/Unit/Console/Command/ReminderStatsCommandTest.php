<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Console\Command;

use Magento\Framework\Console\Cli;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderEfficacyInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderEfficacyReaderInterface;
use PixelPerfect\UnpaidOrderReminder\Console\Command\ReminderStatsCommand;
use Symfony\Component\Console\Tester\CommandTester;

class ReminderStatsCommandTest extends TestCase
{
    public function testPrintsTheFourGroupsAndTheConversionRate(): void
    {
        $tester = $this->executeCommand($this->efficacy(10, 1000.0, 4, 450.5, 2, 199.5, 4, 350.0));

        $display = $tester->getDisplay();
        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Reminded', $display);
        $this->assertStringContainsString('Paid after the reminder', $display);
        $this->assertStringContainsString('Still unpaid', $display);
        $this->assertStringContainsString('Expired unpaid', $display);
        $this->assertStringContainsString('40.0%', $display);
    }

    /**
     * Dividing by an empty population must not print a rate at all, let alone crash.
     */
    public function testReportsAnEmptyLogWithoutDividingByZero(): void
    {
        $tester = $this->executeCommand($this->efficacy(0, 0.0, 0, 0.0, 0, 0.0, 0, 0.0));

        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No reminder has been sent yet.', $tester->getDisplay());
    }

    public function testPassesTheSinceOptionToTheReader(): void
    {
        $reader = $this->createMock(ReminderEfficacyReaderInterface::class);
        $reader->expects($this->once())
            ->method('read')
            ->with('2026-08-01 00:00:00')
            ->willReturn($this->efficacy(0, 0.0, 0, 0.0, 0, 0.0, 0, 0.0));

        $tester = new CommandTester(new ReminderStatsCommand($reader));
        $tester->execute(['--since' => '2026-08-01 00:00:00']);
    }

    private function executeCommand(ReminderEfficacyInterface $efficacy): CommandTester
    {
        $reader = $this->createMock(ReminderEfficacyReaderInterface::class);
        $reader->method('read')->willReturn($efficacy);

        $tester = new CommandTester(new ReminderStatsCommand($reader));
        $tester->execute([]);

        return $tester;
    }

    private function efficacy(
        int $remindedCount,
        float $remindedValue,
        int $paidCount,
        float $paidValue,
        int $stillUnpaidCount,
        float $stillUnpaidValue,
        int $expiredCount,
        float $expiredValue
    ): ReminderEfficacyInterface {
        $efficacy = $this->createMock(ReminderEfficacyInterface::class);
        $efficacy->method('getRemindedCount')->willReturn($remindedCount);
        $efficacy->method('getRemindedValue')->willReturn($remindedValue);
        $efficacy->method('getPaidCount')->willReturn($paidCount);
        $efficacy->method('getPaidValue')->willReturn($paidValue);
        $efficacy->method('getStillUnpaidCount')->willReturn($stillUnpaidCount);
        $efficacy->method('getStillUnpaidValue')->willReturn($stillUnpaidValue);
        $efficacy->method('getExpiredCount')->willReturn($expiredCount);
        $efficacy->method('getExpiredValue')->willReturn($expiredValue);

        return $efficacy;
    }
}
