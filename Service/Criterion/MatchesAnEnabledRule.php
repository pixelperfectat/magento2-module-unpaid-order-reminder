<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service\Criterion;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ConfigInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderCriterionInterface;

/**
 * Restricts the set to orders matching a configured rule, each with its own age.
 *
 * The delay differs per method by design - a hosted transfer expires in about a fortnight, an offline
 * order never does - so this emits one method-and-age pair per rule rather than a single interval.
 */
class MatchesAnEnabledRule implements ReminderCriterionInterface
{
    /**
     * @param ConfigInterface $config
     * @param string $paymentTable Overridable in di.xml.
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly string $paymentTable = 'sales_order_payment'
    ) {
    }

    /**
     * The age cutoff is computed by the database. sales_order.created_at is written by MySQL's
     * CURRENT_TIMESTAMP, so it carries the database server's own timezone, which is not necessarily
     * UTC. NOW() is by definition the same clock that wrote the column, so the window is the
     * configured number of days on any server. A cutoff computed in PHP is only correct while the two
     * clocks agree.
     *
     * The delay from getDelayDays() is typed int and validated at save time, so interpolating it is
     * safe.
     *
     * @param AbstractCollection $collection
     * @param int|null $storeId
     * @return void
     */
    public function apply(AbstractCollection $collection, ?int $storeId): void
    {
        $rules = $this->config->getRules($storeId);

        if ($rules === []) {
            // No rule means nothing qualifies. Leaving the collection unbounded here would select
            // every unpaid order in the shop.
            $collection->getSelect()->where('1 = 0');

            return;
        }

        $connection = $collection->getSelect()->getConnection();

        $groups = [];
        foreach ($rules as $rule) {
            $groups[] = sprintf(
                '(EXISTS (SELECT 1 FROM %s AS pp_payment'
                . ' WHERE pp_payment.parent_id = main_table.entity_id AND pp_payment.method = %s)'
                . ' AND main_table.created_at <= DATE_SUB(NOW(), INTERVAL %d DAY))',
                $collection->getTable($this->paymentTable),
                $connection->quote($rule->getPaymentMethod()),
                $rule->getDelayDays()
            );
        }

        $collection->getSelect()->where(implode(' OR ', $groups));
    }
}
