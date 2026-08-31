<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderLogInterface;

interface ReminderLogRepositoryInterface
{
    /**
     * Save a reminder log entry.
     *
     * @param ReminderLogInterface $log
     * @return ReminderLogInterface
     * @throws CouldNotSaveException
     */
    public function save(ReminderLogInterface $log): ReminderLogInterface;

    /**
     * Get the reminder log entry for an order, if one exists.
     *
     * @param int $orderId
     * @return ReminderLogInterface|null
     */
    public function getByOrderId(int $orderId): ?ReminderLogInterface;

    /**
     * Check whether an order has already been sent a reminder.
     *
     * @param int $orderId
     * @return bool
     */
    public function hasBeenReminded(int $orderId): bool;
}
