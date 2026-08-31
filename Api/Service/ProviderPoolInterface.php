<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Api\Service;

/**
 * The registry of payment methods this installation can write instructions for.
 *
 * It is populated from di.xml by whichever packages are installed, and it is the ONLY declaration of
 * that list. The admin dropdown and the order selection both read it, so neither can name a method
 * that has no provider.
 */
interface ProviderPoolInterface
{
    /**
     * Returns the provider for a payment method.
     *
     * @param string $methodCode
     * @return PaymentInstructionsProviderInterface|null
     */
    public function getProvider(string $methodCode): ?PaymentInstructionsProviderInterface;

    /**
     * Returns whether the pool has a provider for a payment method.
     *
     * @param string $methodCode
     * @return bool
     */
    public function supports(string $methodCode): bool;

    /**
     * Returns all supported payment method codes.
     *
     * @return array<int, string> payment method codes
     */
    public function getSupportedMethods(): array;
}
