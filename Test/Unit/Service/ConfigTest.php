<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ProviderPoolInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Data\ReminderRule;
use PixelPerfect\UnpaidOrderReminder\Model\Data\ReminderRuleFactory;
use PixelPerfect\UnpaidOrderReminder\Service\Config;

class ConfigTest extends TestCase
{
    /** @var ScopeConfigInterface|MockObject */
    private $scopeConfig;
    /** @var ProviderPoolInterface|MockObject */
    private $pool;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->pool = $this->createMock(ProviderPoolInterface::class);
        $this->pool->method('supports')->willReturnCallback(
            static fn (string $code): bool => in_array($code, ['banktransfer', 'checkmo'], true)
        );

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('unserialize')->willReturnCallback(
            static fn (string $json): array => json_decode($json, true) ?? []
        );

        $ruleFactory = $this->createMock(ReminderRuleFactory::class);
        $ruleFactory->method('create')->willReturnCallback(
            static fn (array $data = []): ReminderRule => new ReminderRule(...$data)
        );

        $this->config = new Config($this->scopeConfig, $this->pool, $serializer, $ruleFactory);
    }

    public function testIsDisabledByDefault(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $this->assertFalse($this->config->isEnabled(1));
    }

    public function testReadsTheEnabledFlagAtStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('pixelperfect_unpaid_order_reminder/general/enabled', ScopeInterface::SCOPE_STORE, 5)
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled(5));
    }

    public function testReturnsTheConfiguredRulesKeyedByPaymentMethod(): void
    {
        $this->stubRules('{"banktransfer":{"delay_days":7,"email_template":"tpl_a"}}');

        $rules = $this->config->getRules(1);

        $this->assertArrayHasKey('banktransfer', $rules);
        $this->assertSame('banktransfer', $rules['banktransfer']->getPaymentMethod());
        $this->assertSame(7, $rules['banktransfer']->getDelayDays());
        $this->assertSame('tpl_a', $rules['banktransfer']->getEmailTemplate());
    }

    /**
     * Uninstalling a provider package must not break the cron. Its rule survives in the database,
     * and is simply ignored until the package returns.
     */
    public function testDropsARuleWhoseMethodHasNoProvider(): void
    {
        $this->stubRules(
            '{"banktransfer":{"delay_days":7,"email_template":"tpl_a"},'
            . '"mollie_methods_banktransfer":{"delay_days":5,"email_template":"tpl_b"}}'
        );

        $rules = $this->config->getRules(1);

        $this->assertSame(['banktransfer'], array_keys($rules));
    }

    public function testReturnsNoRulesWhenNothingIsConfigured(): void
    {
        $this->stubRules(null);

        $this->assertSame([], $this->config->getRules(1));
    }

    public function testReturnsNoRulesWhenTheStoredValueIsNotDecodable(): void
    {
        $this->stubRules('not json');

        $this->assertSame([], $this->config->getRules(1));
    }

    public function testSplitsTheBccListOnCommasAndTrimsIt(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === 'pixelperfect_unpaid_order_reminder/general/bcc'
                ? ' ops@example.com , finance@example.com '
                : null
        );

        $this->assertSame(['ops@example.com', 'finance@example.com'], $this->config->getBcc(1));
    }

    public function testAnEmptyBccIsAnEmptyList(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');

        $this->assertSame([], $this->config->getBcc(1));
    }

    /**
     * @param string|null $json
     * @return void
     */
    private function stubRules(?string $json): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === 'pixelperfect_unpaid_order_reminder/rules/methods'
                ? $json
                : null
        );
    }
}
