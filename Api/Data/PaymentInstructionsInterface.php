<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Data;

/**
 * What a shopper needs in order to complete a payment they have not made yet.
 *
 * The union of two shapes: a free-text block, as an offline method stores in configuration, and
 * structured bank details, as a payment provider issues per payment. Every field is optional, so one
 * contract serves both and a template renders whichever half is populated.
 */
interface PaymentInstructionsInterface
{
    /**
     * Get free-text instructions.
     *
     * Free-text instructions, as configured for an offline payment method. May contain HTML.
     *
     * @return string|null
     */
    public function getInstructionsHtml(): ?string;

    /**
     * Get bank name.
     *
     * @return string|null
     */
    public function getBankName(): ?string;

    /**
     * Get bank account.
     *
     * The account the shopper must pay into. For a hosted provider this is the provider's own
     * collection account, not the merchant's.
     *
     * @return string|null
     */
    public function getBankAccount(): ?string;

    /**
     * Get bank BIC.
     *
     * @return string|null
     */
    public function getBankBic(): ?string;

    /**
     * Get payment reference.
     *
     * The reference the shopper must quote. A provider-generated reference for a hosted payment, or
     * the order increment id for an offline one.
     *
     * @return string|null
     */
    public function getReference(): ?string;

    /**
     * Get payment expiration time.
     *
     * The moment the payment can no longer be completed, as 'Y-m-d H:i:s' in UTC.
     * Null means the payment never expires, which is the normal case for an offline method.
     *
     * @return string|null
     */
    public function getExpiresAt(): ?string;

    /**
     * Get payment URL.
     *
     * A provider-hosted page that shows these instructions, if the provider offers one.
     *
     * @return string|null
     */
    public function getPaymentUrl(): ?string;

    /**
     * Check if structured bank details are available.
     *
     * Whether the bank table can be rendered: a name, an account and a reference are all present.
     *
     * @return bool
     */
    public function hasStructuredBankDetails(): bool;
}
