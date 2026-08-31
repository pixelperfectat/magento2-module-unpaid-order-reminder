<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service;

use DateTime;
use DateTimeZone;
use IntlDateFormatter;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\SenderResolverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\PaymentInstructionsInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRuleInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ConfigInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderSenderInterface;

/**
 * Builds and sends the unpaid-order reminder email inside store emulation.
 */
class ReminderSender implements ReminderSenderInterface
{
    /**
     * @param TransportBuilder $transportBuilder
     * @param Emulation $emulation
     * @param ConfigInterface $config
     * @param SenderResolverInterface $senderResolver
     * @param PriceCurrencyInterface $priceCurrency
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly Emulation $emulation,
        private readonly ConfigInterface $config,
        private readonly SenderResolverInterface $senderResolver,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Send one reminder.
     *
     * @param OrderInterface $order
     * @param PaymentInstructionsInterface $instructions
     * @param ReminderRuleInterface $rule
     * @return void
     * @throws MailException
     */
    public function send(
        OrderInterface $order,
        PaymentInstructionsInterface $instructions,
        ReminderRuleInterface $rule
    ): void {
        $email = trim((string)$order->getCustomerEmail());
        if ($email === '') {
            throw new MailException(
                __('Order %1 has no email address, so no reminder can be sent.', (string)$order->getEntityId())
            );
        }

        $storeId = (int)$order->getStoreId();

        // A cron has no storefront request. Without emulation the locale, the currency format and
        // every URL resolve in admin context.
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

        try {
            $builder = $this->transportBuilder
                ->setTemplateIdentifier($rule->getEmailTemplate())
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'order' => $order,
                    'instructions' => $instructions,
                    'store_id' => $storeId,
                    'formattedTotal' => $this->priceCurrency->format(
                        (float)$order->getGrandTotal(),
                        false,
                        PriceCurrencyInterface::DEFAULT_PRECISION,
                        $storeId,
                        (string)$order->getOrderCurrencyCode()
                    ),
                    'formattedDeadline' => $instructions->getExpiresAt() === null
                        ? ''
                        // The instructions carry UTC; the shopper reads the store's own clock.
                        : $this->timezone->formatDateTime(
                            new DateTime($instructions->getExpiresAt(), new DateTimeZone('UTC')),
                            IntlDateFormatter::MEDIUM,
                            IntlDateFormatter::SHORT,
                            null,
                            $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (string)$storeId)
                        ),
                ]);

            $from = $this->senderResolver->resolve($this->config->getSender($storeId), $storeId);
            $builder->setFromByScope($from, $storeId);
            $builder->addTo($email, $this->customerName($order));

            $bcc = $this->config->getBcc($storeId);
            if ($bcc !== []) {
                $builder->addBcc($bcc);
            }

            $builder->getTransport()->sendMessage();
        } finally {
            // A failed send must not leave the whole run emulating one store.
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * Build the display name to send to.
     *
     * @param OrderInterface $order
     * @return string
     */
    private function customerName(OrderInterface $order): string
    {
        $name = trim(sprintf(
            '%s %s',
            (string)$order->getCustomerFirstname(),
            (string)$order->getCustomerLastname()
        ));

        return $name !== '' ? $name : (string)$order->getCustomerEmail();
    }
}
