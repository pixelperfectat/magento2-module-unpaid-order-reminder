<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service\Criterion;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderCriterionInterface;

/**
 * Runs every configured criterion against the same collection.
 */
class CompositeReminderCriterion implements ReminderCriterionInterface
{
    /**
     * @var array<int, ReminderCriterionInterface>
     */
    private array $criteria;

    /**
     * @param array<int|string, ReminderCriterionInterface|null> $criteria A null item is how an
     *     integrator switches a shipped rule off in di.xml.
     */
    public function __construct(array $criteria = [])
    {
        $this->criteria = array_values(array_filter(
            $criteria,
            static fn (?ReminderCriterionInterface $criterion): bool => $criterion !== null
        ));
    }

    /**
     * Runs every criterion against the collection.
     *
     * Each narrows the collection with its own predicate, so there is nothing to short-circuit on.
     *
     * @param AbstractCollection $collection
     * @param int|null $storeId
     * @return void
     */
    public function apply(AbstractCollection $collection, ?int $storeId): void
    {
        foreach ($this->criteria as $criterion) {
            $criterion->apply($collection, $storeId);
        }
    }
}
