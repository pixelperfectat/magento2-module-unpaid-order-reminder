<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service\Criterion;

use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRuleInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ConfigInterface;
use PixelPerfect\UnpaidOrderReminder\Service\Criterion\MatchesAnEnabledRule;

class MatchesAnEnabledRuleTest extends TestCase
{
    use RealSelectTrait;

    public function testEmitsOneMethodAndDelayPairPerRule(): void
    {
        $select = $this->createRealSelect();
        $criterion = new MatchesAnEnabledRule($this->configWith(['banktransfer' => 7, 'checkmo' => 5]));

        $criterion->apply($this->createCollectionWithSelect($select, 'sales_order_payment'), 1);

        $sql = $select->assemble();
        $this->assertStringContainsString(
            "(EXISTS (SELECT 1 FROM sales_order_payment AS pp_payment"
            . " WHERE pp_payment.parent_id = main_table.entity_id AND pp_payment.method = 'banktransfer')"
            . " AND main_table.created_at <= DATE_SUB(NOW(), INTERVAL 7 DAY))",
            $sql
        );
        $this->assertStringContainsString(
            "pp_payment.method = 'checkmo')"
            . " AND main_table.created_at <= DATE_SUB(NOW(), INTERVAL 5 DAY))",
            $sql
        );
        $this->assertStringContainsString(') OR (', $sql);

        // The regression this bound exists to prevent: sales_order has no payment_method column.
        $this->assertStringNotContainsString('main_table.payment_method', $sql);
    }

    /**
     * The regression this criterion exists to prevent. sales_order.created_at is written by MySQL's
     * CURRENT_TIMESTAMP, so it carries the database server's own timezone, which is not always UTC.
     * A cutoff rendered as a literal datetime by any PHP clock is wrong by the offset between them.
     */
    public function testRendersNoDatetimeLiteral(): void
    {
        $select = $this->createRealSelect();

        (new MatchesAnEnabledRule($this->configWith(['banktransfer' => 5])))
            ->apply($this->createCollectionWithSelect($select, 'sales_order_payment'), 1);

        $sql = $select->assemble();
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $sql);
        $this->assertStringContainsString('NOW()', $sql);
    }

    /**
     * With no rules configured, nothing is eligible. An unbounded collection here would mail every
     * unpaid order in the shop.
     */
    public function testMatchesNothingWhenNoRuleIsConfigured(): void
    {
        $select = $this->createRealSelect();

        (new MatchesAnEnabledRule($this->configWith([])))
            ->apply($this->createCollectionWithSelect($select), 1);

        $this->assertStringContainsString('1 = 0', $select->assemble());
    }

    public function testPassesTheStoreIdToTheConfig(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->expects($this->once())->method('getRules')->with(9)->willReturn([]);

        (new MatchesAnEnabledRule($config))->apply($this->createCollectionWithSelect($this->createRealSelect()), 9);
    }

    /**
     * @param array<string, int> $methodToDelay
     * @return ConfigInterface
     */
    private function configWith(array $methodToDelay): ConfigInterface
    {
        $rules = [];
        foreach ($methodToDelay as $method => $delay) {
            $rule = $this->createMock(ReminderRuleInterface::class);
            $rule->method('getPaymentMethod')->willReturn($method);
            $rule->method('getDelayDays')->willReturn($delay);
            $rules[$method] = $rule;
        }

        $config = $this->createMock(ConfigInterface::class);
        $config->method('getRules')->willReturn($rules);

        return $config;
    }
}
