<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderLogInterface;
use PixelPerfect\UnpaidOrderReminder\Api\ReminderLogRepositoryInterface;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\ReminderLog as ReminderLogResource;

class ReminderLogRepository implements ReminderLogRepositoryInterface
{
    /**
     * Constructor.
     *
     * @param ReminderLogResource $resource
     * @param ReminderLogFactory $logFactory
     */
    public function __construct(
        private readonly ReminderLogResource $resource,
        private readonly ReminderLogFactory $logFactory
    ) {
    }

    /**
     * Save a reminder log entry.
     *
     * @param ReminderLogInterface $log
     * @return ReminderLogInterface
     * @throws CouldNotSaveException
     */
    public function save(ReminderLogInterface $log): ReminderLogInterface
    {
        try {
            /** @var ReminderLog $log */
            $this->resource->save($log);
        } catch (\Exception $e) {
            // A unique-constraint violation lands here. The caller must see it: silently continuing
            // would mean a second mail was sent for an order that already had one.
            //
            // Caught as \Exception, not \Throwable: CouldNotSaveException's constructor only accepts
            // \Exception as its cause, and an \Error here would mean a code bug, not a save failure —
            // that should crash loudly, not be repackaged as a friendly "could not save" message.
            throw new CouldNotSaveException(
                __('Could not save the unpaid order reminder log: %1', $e->getMessage()),
                $e
            );
        }

        return $log;
    }

    /**
     * Get the reminder log entry for an order, if one exists.
     *
     * @param int $orderId
     * @return ReminderLogInterface|null
     */
    public function getByOrderId(int $orderId): ?ReminderLogInterface
    {
        $log = $this->logFactory->create();
        $this->resource->load($log, $orderId, ReminderLogInterface::ORDER_ID);

        return $log->getId() === null ? null : $log;
    }

    /**
     * Check whether an order has already been sent a reminder.
     *
     * @param int $orderId
     * @return bool
     */
    public function hasBeenReminded(int $orderId): bool
    {
        return $this->getByOrderId($orderId) !== null;
    }
}
