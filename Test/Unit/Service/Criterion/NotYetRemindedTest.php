<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service\Criterion;

use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Service\Criterion\NotYetReminded;

class NotYetRemindedTest extends TestCase
{
    use RealSelectTrait;

    public function testExcludesAnOrderThatAlreadyHasALogRow(): void
    {
        $select = $this->createRealSelect();
        $collection = $this->createCollectionWithSelect($select, 'pixelperfect_unpaid_order_reminder');

        (new NotYetReminded())->apply($collection, 1);

        $this->assertStringContainsString(
            'NOT EXISTS (SELECT 1 FROM pixelperfect_unpaid_order_reminder AS pp_reminder '
            . 'WHERE pp_reminder.order_id = main_table.entity_id)',
            $select->assemble()
        );
    }

    public function testResolvesTheLogTableThroughTheCollection(): void
    {
        $select = $this->createRealSelect();
        $collection = $this->createCollectionWithSelect($select, 'pfx_pixelperfect_unpaid_order_reminder');

        (new NotYetReminded())->apply($collection, 1);

        $this->assertStringContainsString('FROM pfx_pixelperfect_unpaid_order_reminder AS pp_reminder', $select->assemble());
    }
}
