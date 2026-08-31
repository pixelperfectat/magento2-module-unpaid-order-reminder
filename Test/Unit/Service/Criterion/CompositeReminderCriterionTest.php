<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service\Criterion;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderCriterionInterface;
use PixelPerfect\UnpaidOrderReminder\Service\Criterion\CompositeReminderCriterion;

class CompositeReminderCriterionTest extends TestCase
{
    public function testAppliesEveryCriterionWithoutShortCircuiting(): void
    {
        $collection = $this->createMock(AbstractCollection::class);

        $first = $this->createMock(ReminderCriterionInterface::class);
        $first->expects($this->once())->method('apply')->with($collection, 3);

        $second = $this->createMock(ReminderCriterionInterface::class);
        $second->expects($this->once())->method('apply')->with($collection, 3);

        (new CompositeReminderCriterion([$first, $second]))->apply($collection, 3);
    }

    /**
     * An empty composite must not silently select every order.
     */
    public function testAnEmptyCompositeIsANoop(): void
    {
        $collection = $this->createMock(AbstractCollection::class);

        (new CompositeReminderCriterion())->apply($collection, 3);

        $this->addToAssertionCount(1);
    }

    /**
     * An integrator switches a shipped rule off with a null item in di.xml.
     */
    public function testIgnoresACriterionDisabledWithANullItem(): void
    {
        $collection = $this->createMock(AbstractCollection::class);

        $kept = $this->createMock(ReminderCriterionInterface::class);
        $kept->expects($this->once())->method('apply');

        (new CompositeReminderCriterion([null, $kept]))->apply($collection, 3);
    }
}
