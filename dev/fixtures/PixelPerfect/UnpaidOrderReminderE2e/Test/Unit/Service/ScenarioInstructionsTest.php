<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderE2e\Test\Unit\Service;

use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructions;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructionsFactory;
use PixelPerfect\UnpaidOrderReminderE2e\Model\ScenarioReader;
use PixelPerfect\UnpaidOrderReminderE2e\Service\ScenarioInstructions;

class ScenarioInstructionsTest extends TestCase
{
    /** @var ScenarioReader|MockObject */
    private $reader;
    /** @var PaymentInstructionsFactory|MockObject */
    private $factory;
    /** @var OrderInterface|MockObject */
    private $order;
    private ScenarioInstructions $provider;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ScenarioReader::class);
        $this->factory = $this->createMock(PaymentInstructionsFactory::class);
        $this->order = $this->createMock(OrderInterface::class);
        $this->factory->method('create')->willReturnCallback(
            static fn (array $args): PaymentInstructions => new PaymentInstructions(...$args)
        );
        $this->provider = new ScenarioInstructions($this->reader, $this->factory);
    }

    public function testReturnsNullWhenThereIsNoScenario(): void
    {
        $this->reader->method('read')->willReturn([]);

        $this->assertNull($this->provider->forOrder($this->order));
    }

    public function testReturnsNullForTheFailKind(): void
    {
        $this->reader->method('read')->willReturn(['kind' => 'fail']);

        $this->assertNull($this->provider->forOrder($this->order));
    }

    public function testReturnsStructuredBankDetails(): void
    {
        $this->reader->method('read')->willReturn([
            'kind' => 'bank',
            'bank_name' => 'Example Bank',
            'bank_account' => 'NL10TEST000100100',
            'bank_bic' => 'TESTNL10',
            'reference' => 'RF00-0000-0000-0000',
            'expires_at' => '2026-09-16 04:00:00',
            'payment_url' => 'https://example.com/pay/abc',
        ]);

        $instructions = $this->provider->forOrder($this->order);

        $this->assertNotNull($instructions);
        $this->assertTrue($instructions->hasStructuredBankDetails());
        $this->assertSame('Example Bank', $instructions->getBankName());
        $this->assertSame('TESTNL10', $instructions->getBankBic());
        $this->assertSame('2026-09-16 04:00:00', $instructions->getExpiresAt());
        $this->assertSame('https://example.com/pay/abc', $instructions->getPaymentUrl());
    }

    public function testReturnsFreeTextForTheTextKind(): void
    {
        $this->reader->method('read')->willReturn([
            'kind' => 'text',
            'instructions_html' => '<p>Pay into the account named on your invoice.</p>',
        ]);

        $instructions = $this->provider->forOrder($this->order);

        $this->assertNotNull($instructions);
        $this->assertFalse($instructions->hasStructuredBankDetails());
        $this->assertSame(
            '<p>Pay into the account named on your invoice.</p>',
            $instructions->getInstructionsHtml()
        );
    }

    public function testReturnsNullWhenTheTextKindHasNoText(): void
    {
        $this->reader->method('read')->willReturn(['kind' => 'text', 'instructions_html' => '   ']);

        $this->assertNull($this->provider->forOrder($this->order));
    }

    public function testReturnsNullForAnUnknownKind(): void
    {
        $this->reader->method('read')->willReturn(['kind' => 'something-else']);

        $this->assertNull($this->provider->forOrder($this->order));
    }
}
