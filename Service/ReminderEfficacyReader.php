<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service;

use Magento\Sales\Model\Order;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderEfficacyInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderEfficacyReaderInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Data\ReminderEfficacyFactory;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\ReminderLog\CollectionFactory;
use Zend_Db_Expr;

/**
 * Reads how the reminded orders fared: paid, still unpaid, or expired unpaid.
 */
class ReminderEfficacyReader implements ReminderEfficacyReaderInterface
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param ReminderEfficacyFactory $efficacyFactory
     * @param string $orderTable Overridable in di.xml.
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ReminderEfficacyFactory $efficacyFactory,
        private readonly string $orderTable = 'sales_order'
    ) {
    }

    /**
     * One aggregate query. The three outcome groups are conditional sums over the same join, so they
     * are guaranteed to partition the reminded population rather than being three queries that can
     * disagree.
     *
     * @param string|null $sinceGmt
     * @return ReminderEfficacyInterface
     */
    public function read(?string $sinceGmt = null): ReminderEfficacyInterface
    {
        $collection = $this->collectionFactory->create();
        $resource = $collection->getResource();
        $connection = $resource->getConnection();

        $pending = $connection->quote(Order::STATE_PENDING_PAYMENT);
        $canceled = $connection->quote(Order::STATE_CANCELED);

        // Paid: the order has left pending payment, and did so at or after the mail went out. Both
        // columns are whole-second DATETIME, and sent_at is now stamped before the send (see
        // ReminderRunner::processOrder()), so any post-reminder state change has an updated_at no
        // earlier than sent_at by construction; the equal case is the one that remains genuinely
        // possible within a second. An order paid a meaningful margin before the reminder was
        // written is not credited to it.
        $paid = sprintf('(so.state NOT IN (%s, %s) AND so.updated_at >= pp.sent_at)', $pending, $canceled);
        $stillUnpaid = sprintf(
            '(so.state = %s AND (pp.expires_at IS NULL OR pp.expires_at > UTC_TIMESTAMP()))',
            $pending
        );
        $expired = sprintf(
            '(so.state = %s OR (so.state = %s AND pp.expires_at IS NOT NULL AND pp.expires_at <= UTC_TIMESTAMP()))',
            $canceled,
            $pending
        );

        $select = $connection->select()
            ->from(['pp' => $resource->getTable('pixelperfect_unpaid_order_reminder')], [])
            ->joinInner(
                ['so' => $resource->getTable($this->orderTable)],
                'so.entity_id = pp.order_id',
                []
            )
            ->columns([
                'reminded_count' => new Zend_Db_Expr('COUNT(*)'),
                'reminded_value' => new Zend_Db_Expr('COALESCE(SUM(pp.grand_total), 0)'),
                'paid_count' => new Zend_Db_Expr(sprintf('SUM(%s)', $paid)),
                'paid_value' => new Zend_Db_Expr(
                    sprintf('COALESCE(SUM(CASE WHEN %s THEN pp.grand_total ELSE 0 END), 0)', $paid)
                ),
                'still_unpaid_count' => new Zend_Db_Expr(sprintf('SUM(%s)', $stillUnpaid)),
                'still_unpaid_value' => new Zend_Db_Expr(
                    sprintf('COALESCE(SUM(CASE WHEN %s THEN pp.grand_total ELSE 0 END), 0)', $stillUnpaid)
                ),
                'expired_count' => new Zend_Db_Expr(sprintf('SUM(%s)', $expired)),
                'expired_value' => new Zend_Db_Expr(
                    sprintf('COALESCE(SUM(CASE WHEN %s THEN pp.grand_total ELSE 0 END), 0)', $expired)
                ),
            ]);

        if ($sinceGmt !== null) {
            $select->where('pp.sent_at >= ?', $sinceGmt);
        }

        $row = $connection->fetchRow($select);
        if (!is_array($row)) {
            return $this->efficacyFactory->create();
        }

        return $this->efficacyFactory->create([
            'remindedCount' => (int)($row['reminded_count'] ?? 0),
            'remindedValue' => (float)($row['reminded_value'] ?? 0),
            'paidCount' => (int)($row['paid_count'] ?? 0),
            'paidValue' => (float)($row['paid_value'] ?? 0),
            'stillUnpaidCount' => (int)($row['still_unpaid_count'] ?? 0),
            'stillUnpaidValue' => (float)($row['still_unpaid_value'] ?? 0),
            'expiredCount' => (int)($row['expired_count'] ?? 0),
            'expiredValue' => (float)($row['expired_value'] ?? 0),
        ]);
    }
}
