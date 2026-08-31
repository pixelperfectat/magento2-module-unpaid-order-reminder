<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Data;

/**
 * The record that one order was sent one reminder.
 *
 * This is written, never updated. Whether the order was subsequently paid is read from the order
 * itself, so this module never has to observe a payment.
 */
interface ReminderLogInterface
{
    public const ENTITY_ID = 'entity_id';
    public const ORDER_ID = 'order_id';
    public const STORE_ID = 'store_id';
    public const PAYMENT_METHOD = 'payment_method';
    public const SENT_AT = 'sent_at';
    public const EXPIRES_AT = 'expires_at';
    public const GRAND_TOTAL = 'grand_total';

    /**
     * Get the reminded order's entity ID.
     *
     * @return int
     */
    public function getOrderId(): int;

    /**
     * Set the reminded order's entity ID.
     *
     * @param int $orderId
     * @return self
     */
    public function setOrderId(int $orderId): self;

    /**
     * Get the store the order was placed on.
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Set the store the order was placed on.
     *
     * @param int $storeId
     * @return self
     */
    public function setStoreId(int $storeId): self;

    /**
     * Get the payment method code the rule matched.
     *
     * @return string
     */
    public function getPaymentMethod(): string;

    /**
     * Set the payment method code the rule matched.
     *
     * @param string $paymentMethod
     * @return self
     */
    public function setPaymentMethod(string $paymentMethod): self;

    /**
     * Get when the reminder was sent.
     *
     * @return string 'Y-m-d H:i:s' UTC
     */
    public function getSentAt(): string;

    /**
     * Set when the reminder was sent.
     *
     * @param string $sentAt
     * @return self
     */
    public function setSentAt(string $sentAt): self;

    /**
     * Get when the payment window closes.
     *
     * @return string|null 'Y-m-d H:i:s' UTC, or null when the payment never expires
     */
    public function getExpiresAt(): ?string;

    /**
     * Set when the payment window closes.
     *
     * @param string|null $expiresAt
     * @return self
     */
    public function setExpiresAt(?string $expiresAt): self;

    /**
     * Get the order total frozen at send time.
     *
     * @return float
     */
    public function getGrandTotal(): float;

    /**
     * Set the order total frozen at send time.
     *
     * @param float $grandTotal
     * @return self
     */
    public function setGrandTotal(float $grandTotal): self;
}
