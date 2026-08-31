<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service\Instructions;

use PixelPerfect\UnpaidOrderReminder\Api\Service\PaymentInstructionsProviderInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ProviderPoolInterface;

class ProviderPool implements ProviderPoolInterface
{
    /**
     * @var array<string, PaymentInstructionsProviderInterface>
     */
    private array $providers;

    /**
     * @param array<string, PaymentInstructionsProviderInterface|null> $providers keyed by payment
     *     method code. A null value is how an integrator switches a shipped provider off in di.xml,
     *     so nulls are stripped rather than stored.
     */
    public function __construct(array $providers = [])
    {
        $this->providers = array_filter(
            $providers,
            static fn (?PaymentInstructionsProviderInterface $provider): bool => $provider !== null
        );
    }

    /**
     * Returns the provider for a payment method.
     *
     * @param string $methodCode
     * @return PaymentInstructionsProviderInterface|null
     */
    public function getProvider(string $methodCode): ?PaymentInstructionsProviderInterface
    {
        return $this->providers[$methodCode] ?? null;
    }

    /**
     * Returns whether the pool has a provider for a payment method.
     *
     * @param string $methodCode
     * @return bool
     */
    public function supports(string $methodCode): bool
    {
        return isset($this->providers[$methodCode]);
    }

    /**
     * Returns all supported payment method codes.
     *
     * @return array<int, string>
     */
    public function getSupportedMethods(): array
    {
        return array_keys($this->providers);
    }
}
