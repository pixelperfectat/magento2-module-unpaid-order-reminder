<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Service;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * One rule narrowing the set of orders that qualify for a reminder.
 *
 * Every criterion adds a WHERE predicate. None of them loads anything, so the whole set is decided in
 * one query.
 */
interface ReminderCriterionInterface
{
    /**
     * Narrows the collection to orders matching this criterion.
     *
     * @param AbstractCollection $collection
     * @param int|null $storeId scope for any configuration the rule reads
     * @return void
     */
    public function apply(AbstractCollection $collection, ?int $storeId): void;
}
