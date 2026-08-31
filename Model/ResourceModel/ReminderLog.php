<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderLogInterface;

class ReminderLog extends AbstractDb
{
    /**
     * Bind the resource model to its table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('pixelperfect_unpaid_order_reminder', ReminderLogInterface::ENTITY_ID);
    }
}
