<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service\Criterion;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderCriterionInterface;

/**
 * Excludes any order that already has a reminder log row.
 */
class NotYetReminded implements ReminderCriterionInterface
{
    /**
     * @param string $logTable Overridable in di.xml.
     */
    public function __construct(
        private readonly string $logTable = 'pixelperfect_unpaid_order_reminder'
    ) {
    }

    /**
     * Excludes any order that already has a reminder log row.
     *
     * A correlated NOT EXISTS rather than a join, so an order is evaluated once and no row is
     * duplicated.
     *
     * @param AbstractCollection $collection
     * @param int|null $storeId
     * @return void
     */
    public function apply(AbstractCollection $collection, ?int $storeId): void
    {
        $collection->getSelect()->where(
            sprintf(
                'NOT EXISTS (SELECT 1 FROM %s AS pp_reminder WHERE pp_reminder.order_id = main_table.entity_id)',
                $collection->getTable($this->logTable)
            )
        );
    }
}
