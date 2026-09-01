<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service\Criterion;

use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ConfigInterface;
use PixelPerfect\UnpaidOrderReminder\Service\Criterion\WithinMaxAge;

class WithinMaxAgeTest extends TestCase
{
    use RealSelectTrait;

    /**
     * The regression this criterion exists to prevent. Without an upper bound, the first run after
     * switch-on selects every unpaid order the shop has ever taken. Measured on a production
     * database in September 2026: 29 card and wallet orders, all over 30 days old, none payable.
     */
    public function testBoundsTheCollectionToTheConfiguredAge(): void
    {
        $select = $this->createRealSelect();

        (new WithinMaxAge($this->configWithMaxAge(30)))
            ->apply($this->createCollectionWithSelect($select), 1);

        $this->assertStringContainsString(
            'main_table.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            $select->assemble()
        );
    }

    /**
     * Both sides of the comparison must come from the database server's clock. sales_order.created_at
     * is written by MySQL, whose timezone is not always UTC, so a cutoff rendered by any PHP clock is
     * wrong by the offset between them - the fault that left a sibling module's idle window inert.
     */
    public function testRendersNoDatetimeLiteral(): void
    {
        $select = $this->createRealSelect();

        (new WithinMaxAge($this->configWithMaxAge(30)))
            ->apply($this->createCollectionWithSelect($select), 1);

        $sql = $select->assemble();
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $sql);
        $this->assertStringContainsString('NOW()', $sql);
    }

    /**
     * Zero is the documented "no limit" value, so it must add nothing at all rather than an
     * INTERVAL 0 DAY bound that would exclude every order older than today.
     */
    public function testAddsNothingWhenTheLimitIsZero(): void
    {
        $select = $this->createRealSelect();

        (new WithinMaxAge($this->configWithMaxAge(0)))
            ->apply($this->createCollectionWithSelect($select), 1);

        $this->assertStringNotContainsString('created_at', $select->assemble());
    }

    /**
     * Config clamps a negative to zero, but the criterion must not depend on that to stay safe.
     */
    public function testAddsNothingWhenTheLimitIsNegative(): void
    {
        $select = $this->createRealSelect();

        (new WithinMaxAge($this->configWithMaxAge(-5)))
            ->apply($this->createCollectionWithSelect($select), 1);

        $this->assertStringNotContainsString('created_at', $select->assemble());
    }

    public function testReadsTheLimitAtTheStoreScope(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->expects($this->once())->method('getMaxAgeDays')->with(7)->willReturn(30);

        (new WithinMaxAge($config))
            ->apply($this->createCollectionWithSelect($this->createRealSelect()), 7);
    }

    private function configWithMaxAge(int $days): ConfigInterface
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getMaxAgeDays')->willReturn($days);

        return $config;
    }
}
