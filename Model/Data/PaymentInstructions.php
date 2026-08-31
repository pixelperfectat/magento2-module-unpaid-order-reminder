<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model\Data;

use PixelPerfect\UnpaidOrderReminder\Api\Data\PaymentInstructionsInterface;

class PaymentInstructions implements PaymentInstructionsInterface
{
    /**
     * @param string|null $instructionsHtml
     * @param string|null $bankName
     * @param string|null $bankAccount
     * @param string|null $bankBic
     * @param string|null $reference
     * @param string|null $expiresAt
     * @param string|null $paymentUrl
     */
    public function __construct(
        private readonly ?string $instructionsHtml = null,
        private readonly ?string $bankName = null,
        private readonly ?string $bankAccount = null,
        private readonly ?string $bankBic = null,
        private readonly ?string $reference = null,
        private readonly ?string $expiresAt = null,
        private readonly ?string $paymentUrl = null
    ) {
    }

    /**
     * Get free-text instructions.
     *
     * @return string|null
     */
    public function getInstructionsHtml(): ?string
    {
        return $this->instructionsHtml;
    }

    /**
     * Get bank name.
     *
     * @return string|null
     */
    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    /**
     * Get bank account.
     *
     * @return string|null
     */
    public function getBankAccount(): ?string
    {
        return $this->bankAccount;
    }

    /**
     * Get bank BIC.
     *
     * @return string|null
     */
    public function getBankBic(): ?string
    {
        return $this->bankBic;
    }

    /**
     * Get payment reference.
     *
     * @return string|null
     */
    public function getReference(): ?string
    {
        return $this->reference;
    }

    /**
     * Get payment expiration time.
     *
     * @return string|null
     */
    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    /**
     * Get payment URL.
     *
     * @return string|null
     */
    public function getPaymentUrl(): ?string
    {
        return $this->paymentUrl;
    }

    /**
     * Check if structured bank details are available.
     *
     * A shopper cannot pay from an account number alone; without the reference the transfer arrives
     * unmatchable, which is worse than not sending it.
     *
     * @return bool
     */
    public function hasStructuredBankDetails(): bool
    {
        return $this->bankName !== null
            && $this->bankAccount !== null
            && $this->reference !== null;
    }
}
