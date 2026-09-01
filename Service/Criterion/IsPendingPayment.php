<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service\Criterion;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Sales\Model\Order;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderCriterionInterface;

/**
 * Restricts the set to orders still awaiting payment.
 *
 * The class is named after `pending_payment`, the state Magento's payment-gateway pattern uses (e.g.
 * the Mollie companion package's bank-transfer method). But Magento's own *offline* payment methods -
 * banktransfer, checkmo, cashondelivery, purchaseorder - never reach that state at all:
 * vendor/magento/module-offline-payments/etc/config.xml sets order_status to "pending", which Magento's
 * status-to-state map resolves to state STATE_NEW, not STATE_PENDING_PAYMENT. An offline order sitting
 * in "new" is exactly as unpaid as one sitting in "pending_payment", so a class called IsPendingPayment
 * has to match both states to do what its name promises. The class keeps its original name regardless:
 * it is published and its fully-qualified name appears in the shipped etc/di.xml, so renaming it would
 * break any integrator override for no benefit.
 */
class IsPendingPayment implements ReminderCriterionInterface
{
    /**
     * The default set of "still awaiting payment" states: the payment-gateway pattern's state plus
     * the state Magento's own offline payment methods actually land in.
     *
     * Shared with {@see \PixelPerfect\UnpaidOrderReminder\Service\ReminderRunner} and
     * {@see \PixelPerfect\UnpaidOrderReminder\Service\ReminderEfficacyReader} - both are wired to this
     * same constant in etc/di.xml, rather than each carrying its own copy of the list, so the three
     * can never drift apart.
     */
    public const PENDING_STATES = [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW];

    /**
     * @param string[] $states Overridable in di.xml for a gateway that parks unpaid orders elsewhere.
     */
    public function __construct(
        private readonly array $states = self::PENDING_STATES
    ) {
    }

    /**
     * Narrows the collection to orders in any of the configured pending states.
     *
     * @param AbstractCollection $collection
     * @param int|null $storeId
     * @return void
     */
    public function apply(AbstractCollection $collection, ?int $storeId): void
    {
        $connection = $collection->getSelect()->getConnection();
        $quoted = array_map(
            static fn (string $state): string => $connection->quote($state),
            $this->states
        );

        $collection->getSelect()->where(sprintf('main_table.state IN (%s)', implode(', ', $quoted)));
    }
}
