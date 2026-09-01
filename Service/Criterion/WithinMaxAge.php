<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service\Criterion;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ConfigInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderCriterionInterface;

/**
 * Excludes orders that are too old to be worth chasing.
 *
 * Without an upper bound the very first run after the module is switched on selects the shop's
 * entire history of unpaid orders at once. On a live shop that is a mass mailing about orders
 * nobody remembers placing. Measured on a production database in September 2026: 29 unpaid card and
 * wallet orders, every one of them more than 30 days old and none of them ever going to be paid.
 *
 * A provider that reports a payment deadline already covers its own methods, because
 * {@see \PixelPerfect\UnpaidOrderReminder\Service\ReminderRunner} skips an order whose window has
 * closed. That is no help for a method with no deadline at all - an offline bank transfer never
 * expires - so the bound belongs in the selection, where it applies to every method equally.
 *
 * The comparison is made entirely in SQL, so both sides come from the database server's clock and
 * the shop's timezone cannot skew it.
 */
class WithinMaxAge implements ReminderCriterionInterface
{
    /**
     * @param ConfigInterface $config
     */
    public function __construct(
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * Bound the collection to orders newer than the configured maximum age.
     *
     * @param AbstractCollection $collection
     * @param int|null $storeId
     * @return void
     */
    public function apply(AbstractCollection $collection, ?int $storeId): void
    {
        $maxAgeDays = $this->config->getMaxAgeDays($storeId);
        if ($maxAgeDays <= 0) {
            return;
        }

        $collection->getSelect()->where(
            sprintf('main_table.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $maxAgeDays)
        );
    }
}
