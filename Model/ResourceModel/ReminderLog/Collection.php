<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\ReminderLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PixelPerfect\UnpaidOrderReminder\Model\ReminderLog as ReminderLogModel;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\ReminderLog as ReminderLogResource;

class Collection extends AbstractCollection
{
    /**
     * Bind the collection to its entity and resource models.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ReminderLogModel::class, ReminderLogResource::class);
    }
}
