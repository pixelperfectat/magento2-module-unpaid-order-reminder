<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderE2e\Service;

use Magento\Sales\Api\Data\OrderInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\PaymentInstructionsInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\PaymentInstructionsProviderInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructionsFactory;
use PixelPerfect\UnpaidOrderReminderE2e\Model\ScenarioReader;

/**
 * A payment instructions provider driven by a file, so a test controls the gateway exactly.
 *
 * It exists because a real hosted gateway cannot be made deterministic: a reminder needs a live
 * payment that a script did not create. This provider stands in for one.
 */
class ScenarioInstructions implements PaymentInstructionsProviderInterface
{
    /**
     * @param ScenarioReader $reader
     * @param PaymentInstructionsFactory $instructionsFactory
     */
    public function __construct(
        private readonly ScenarioReader $reader,
        private readonly PaymentInstructionsFactory $instructionsFactory
    ) {
    }

    /**
     * Produce the instructions the current scenario describes.
     *
     * @param OrderInterface $order
     * @return PaymentInstructionsInterface|null
     */
    public function forOrder(OrderInterface $order): ?PaymentInstructionsInterface
    {
        $scenario = $this->reader->read();
        $kind = (string)($scenario['kind'] ?? 'fail');

        if ($kind === 'bank') {
            return $this->instructionsFactory->create([
                'bankName' => $this->value($scenario, 'bank_name'),
                'bankAccount' => $this->value($scenario, 'bank_account'),
                'bankBic' => $this->value($scenario, 'bank_bic'),
                'reference' => $this->value($scenario, 'reference'),
                'expiresAt' => $this->value($scenario, 'expires_at'),
                'paymentUrl' => $this->value($scenario, 'payment_url'),
            ]);
        }

        if ($kind === 'text') {
            $html = $this->value($scenario, 'instructions_html');
            if ($html === null) {
                return null;
            }
            return $this->instructionsFactory->create(['instructionsHtml' => $html]);
        }

        return null;
    }

    /**
     * Read one scenario field, treating a blank string as absent.
     *
     * @param array<string, mixed> $scenario
     * @param string $key
     * @return string|null
     */
    private function value(array $scenario, string $key): ?string
    {
        $value = $scenario[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
