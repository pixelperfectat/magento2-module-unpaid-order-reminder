<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service\Criterion;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Sales\Model\Order;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderCriterionInterface;

/**
 * Restricts the set to orders sitting in the pending-payment state.
 */
class IsPendingPayment implements ReminderCriterionInterface
{
    /**
     * @param string $state Overridable in di.xml for a gateway that parks unpaid orders elsewhere.
     */
    public function __construct(
        private readonly string $state = Order::STATE_PENDING_PAYMENT
    ) {
    }

    /**
     * Narrows the collection to orders in the configured pending state.
     *
     * @param AbstractCollection $collection
     * @param int|null $storeId
     * @return void
     */
    public function apply(AbstractCollection $collection, ?int $storeId): void
    {
        $collection->getSelect()->where('main_table.state = ?', $this->state);
    }
}
