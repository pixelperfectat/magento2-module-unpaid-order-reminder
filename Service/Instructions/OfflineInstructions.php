<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service\Instructions;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\PaymentInstructionsInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\PaymentInstructionsProviderInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructionsFactory;

/**
 * Instructions for a Magento offline payment method.
 *
 * Every offline method stores its instructions at payment/<code>/instructions, so one provider serves
 * all of them. There is no expiry, because nothing external is holding the payment open, and no
 * provider-issued reference, so the shopper quotes the order number.
 */
class OfflineInstructions implements PaymentInstructionsProviderInterface
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param PaymentInstructionsFactory $instructionsFactory
     * @param string $configPathPattern Overridable in di.xml for a method that stores its
     *     instructions somewhere other than Magento's convention.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PaymentInstructionsFactory $instructionsFactory,
        private readonly string $configPathPattern = 'payment/%s/instructions'
    ) {
    }

    /**
     * Fetch payment instructions for an order.
     *
     * @param OrderInterface $order
     * @return PaymentInstructionsInterface|null
     */
    public function forOrder(OrderInterface $order): ?PaymentInstructionsInterface
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return null;
        }

        $text = $this->scopeConfig->getValue(
            sprintf($this->configPathPattern, (string)$payment->getMethod()),
            ScopeInterface::SCOPE_STORE,
            (int)$order->getStoreId()
        );

        $text = is_string($text) ? trim($text) : '';
        if ($text === '') {
            return null;
        }

        return $this->instructionsFactory->create([
            'instructionsHtml' => $text,
            'reference' => (string)$order->getIncrementId(),
        ]);
    }
}
