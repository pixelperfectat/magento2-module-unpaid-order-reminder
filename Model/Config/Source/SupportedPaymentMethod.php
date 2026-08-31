<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Payment\Model\Config as PaymentConfig;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ProviderPoolInterface;

/**
 * The payment methods a reminder rule may name.
 *
 * Generated from the provider pool, so the dropdown and the technical registry cannot drift apart.
 * A method with no provider is not offered, and therefore cannot be configured.
 */
class SupportedPaymentMethod implements OptionSourceInterface
{
    /**
     * @param ProviderPoolInterface $providerPool
     * @param PaymentConfig $paymentConfig
     */
    public function __construct(
        private readonly ProviderPoolInterface $providerPool,
        private readonly PaymentConfig $paymentConfig
    ) {
    }

    /**
     * Get the option array.
     *
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        // Magento\Payment\Model\Config has no getAllMethods(); getMethodsInfo() is the method-code ->
        // declared-info map assembled from every module's etc/methods.xml, keyed by 'label'.
        $methods = $this->paymentConfig->getMethodsInfo();

        $options = [];
        foreach ($this->providerPool->getSupportedMethods() as $code) {
            $options[] = [
                'value' => $code,
                'label' => (string)($methods[$code]['label'] ?? $code),
            ];
        }

        return $options;
    }
}
