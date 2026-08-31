<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service\Criterion;

use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Service\Criterion\IsPendingPayment;

class IsPendingPaymentTest extends TestCase
{
    use RealSelectTrait;

    public function testRestrictsToOrdersAwaitingPayment(): void
    {
        $select = $this->createRealSelect();

        (new IsPendingPayment())->apply($this->createCollectionWithSelect($select), 1);

        $this->assertStringContainsString("main_table.state = 'pending_payment'", $select->assemble());
    }

    /**
     * The state is a constructor argument so an integrator whose gateway parks orders in a custom
     * state can point the rule at it from di.xml.
     */
    public function testUsesTheInjectedState(): void
    {
        $select = $this->createRealSelect();

        (new IsPendingPayment('awaiting_transfer'))->apply($this->createCollectionWithSelect($select), 1);

        $this->assertStringContainsString("main_table.state = 'awaiting_transfer'", $select->assemble());
    }
}
