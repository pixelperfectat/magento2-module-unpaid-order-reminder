<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model;

use Magento\Framework\Model\AbstractModel;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderLogInterface;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\ReminderLog as ReminderLogResource;

class ReminderLog extends AbstractModel implements ReminderLogInterface
{
    public const TABLE = 'pixelperfect_unpaid_order_reminder';

    /**
     * Bind the entity to its resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ReminderLogResource::class);
    }

    /**
     * Get the reminded order's entity ID.
     *
     * @return int
     */
    public function getOrderId(): int
    {
        return (int)$this->getData(self::ORDER_ID);
    }

    /**
     * Set the reminded order's entity ID.
     *
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    /**
     * Get the store the order was placed on.
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return (int)$this->getData(self::STORE_ID);
    }

    /**
     * Set the store the order was placed on.
     *
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    /**
     * Get the payment method code the rule matched.
     *
     * @return string
     */
    public function getPaymentMethod(): string
    {
        return (string)$this->getData(self::PAYMENT_METHOD);
    }

    /**
     * Set the payment method code the rule matched.
     *
     * @param string $paymentMethod
     * @return $this
     */
    public function setPaymentMethod(string $paymentMethod): self
    {
        return $this->setData(self::PAYMENT_METHOD, $paymentMethod);
    }

    /**
     * Get when the reminder was sent.
     *
     * @return string
     */
    public function getSentAt(): string
    {
        return (string)$this->getData(self::SENT_AT);
    }

    /**
     * Set when the reminder was sent.
     *
     * @param string $sentAt
     * @return $this
     */
    public function setSentAt(string $sentAt): self
    {
        return $this->setData(self::SENT_AT, $sentAt);
    }

    /**
     * Get when the payment window closes.
     *
     * @return string|null
     */
    public function getExpiresAt(): ?string
    {
        $value = $this->getData(self::EXPIRES_AT);

        return $value === null ? null : (string)$value;
    }

    /**
     * Set when the payment window closes.
     *
     * @param string|null $expiresAt
     * @return $this
     */
    public function setExpiresAt(?string $expiresAt): self
    {
        return $this->setData(self::EXPIRES_AT, $expiresAt);
    }

    /**
     * Get the order total frozen at send time.
     *
     * @return float
     */
    public function getGrandTotal(): float
    {
        return (float)$this->getData(self::GRAND_TOTAL);
    }

    /**
     * Set the order total frozen at send time.
     *
     * @param float $grandTotal
     * @return $this
     */
    public function setGrandTotal(float $grandTotal): self
    {
        return $this->setData(self::GRAND_TOTAL, $grandTotal);
    }
}
