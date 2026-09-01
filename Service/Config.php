<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\ScopeInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRuleInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ConfigInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ProviderPoolInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Data\ReminderRuleFactory;

/**
 * Reads the module's admin configuration.
 */
class Config implements ConfigInterface
{
    private const PATH_ENABLED = 'pixelperfect_unpaid_order_reminder/general/enabled';
    private const PATH_SENDER = 'pixelperfect_unpaid_order_reminder/general/sender';
    private const PATH_BCC = 'pixelperfect_unpaid_order_reminder/general/bcc';
    private const PATH_RULES = 'pixelperfect_unpaid_order_reminder/rules/methods';
    private const PATH_MAX_AGE_DAYS = 'pixelperfect_unpaid_order_reminder/general/max_age_days';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ProviderPoolInterface $providerPool
     * @param SerializerInterface $serializer
     * @param ReminderRuleFactory $ruleFactory
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProviderPoolInterface $providerPool,
        private readonly SerializerInterface $serializer,
        private readonly ReminderRuleFactory $ruleFactory
    ) {
    }

    /**
     * Whether the module is enabled at the given scope.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * A rule whose method has no provider is dropped rather than reported as an error: uninstalling
     * a provider package leaves its rule behind in the database, and that must not stop the cron
     * from serving every other method.
     *
     * @param int|null $storeId
     * @return array<string, ReminderRuleInterface>
     */
    public function getRules(?int $storeId = null): array
    {
        $raw = $this->scopeConfig->getValue(self::PATH_RULES, ScopeInterface::SCOPE_STORE, $storeId);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $rules = [];
        foreach ($decoded as $method => $row) {
            $method = (string)$method;
            if (!is_array($row) || !$this->providerPool->supports($method)) {
                continue;
            }

            $rules[$method] = $this->ruleFactory->create([
                'paymentMethod' => $method,
                'delayDays' => (int)($row['delay_days'] ?? 0),
                'emailTemplate' => (string)($row['email_template'] ?? ''),
            ]);
        }

        return $rules;
    }

    /**
     * @inheritDoc
     */
    public function getMaxAgeDays(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(self::PATH_MAX_AGE_DAYS, ScopeInterface::SCOPE_STORE, $storeId);
        if (!is_numeric($value)) {
            return 0;
        }

        // A negative bound would exclude every order. Read as "no bound", which is what an operator
        // who typed one is far more likely to have meant than "send nothing, silently".
        return max(0, (int)$value);
    }

    /**
     * Get the store email identity to send from.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSender(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(self::PATH_SENDER, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Get the list of BCC addresses.
     *
     * @param int|null $storeId
     * @return array<int, string>
     */
    public function getBcc(?int $storeId = null): array
    {
        $raw = (string)$this->scopeConfig->getValue(self::PATH_BCC, ScopeInterface::SCOPE_STORE, $storeId);
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
