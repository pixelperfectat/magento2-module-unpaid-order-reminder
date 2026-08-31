<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service\Instructions;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructions;
use PixelPerfect\UnpaidOrderReminder\Model\Data\PaymentInstructionsFactory;
use PixelPerfect\UnpaidOrderReminder\Service\Instructions\OfflineInstructions;

class OfflineInstructionsTest extends TestCase
{
    /** @var ScopeConfigInterface|MockObject */
    private $scopeConfig;
    private OfflineInstructions $provider;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $factory = $this->createMock(PaymentInstructionsFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data = []): PaymentInstructions => new PaymentInstructions(...$data)
        );

        $this->provider = new OfflineInstructions($this->scopeConfig, $factory);
    }

    public function testReadsTheConfiguredInstructionsForTheOrdersMethod(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('payment/banktransfer/instructions', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn('Transfer to IBAN AT00 0000 0000 0000 0000.');

        $instructions = $this->provider->forOrder($this->order('banktransfer', '000000123', 7));

        $this->assertNotNull($instructions);
        $this->assertSame('Transfer to IBAN AT00 0000 0000 0000 0000.', $instructions->getInstructionsHtml());
    }

    /**
     * An offline method has no provider-issued reference, so the shopper quotes the order number.
     */
    public function testUsesTheOrderIncrementIdAsTheReference(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('Pay us.');

        $instructions = $this->provider->forOrder($this->order('checkmo', '000000456', 1));

        $this->assertNotNull($instructions);
        $this->assertSame('000000456', $instructions->getReference());
    }

    /**
     * An offline order never expires, and there is no hosted page to send anyone to.
     */
    public function testCarriesNoExpiryAndNoPaymentUrl(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('Pay us.');

        $instructions = $this->provider->forOrder($this->order('banktransfer', '000000123', 1));

        $this->assertNotNull($instructions);
        $this->assertNull($instructions->getExpiresAt());
        $this->assertNull($instructions->getPaymentUrl());
    }

    /**
     * A mail whose only content is an empty configuration field is worse than no mail.
     */
    public function testReturnsNullWhenTheMethodHasNoConfiguredInstructions(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertNull($this->provider->forOrder($this->order('banktransfer', '000000123', 1)));
    }

    public function testReturnsNullWhenTheConfiguredInstructionsAreBlank(): void
    {
        $this->scopeConfig->method('getValue')->willReturn("   \n  ");

        $this->assertNull($this->provider->forOrder($this->order('banktransfer', '000000123', 1)));
    }

    public function testReturnsNullWhenTheOrderHasNoPayment(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn(null);

        $this->assertNull($this->provider->forOrder($order));
    }

    /**
     * The path is a constructor argument so an integrator can point it elsewhere in di.xml.
     */
    public function testUsesTheInjectedConfigPathPattern(): void
    {
        $factory = $this->createMock(PaymentInstructionsFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data = []): PaymentInstructions => new PaymentInstructions(...$data)
        );

        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('custom/banktransfer/text', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn('Pay us.');

        $provider = new OfflineInstructions($this->scopeConfig, $factory, 'custom/%s/text');

        $this->assertNotNull($provider->forOrder($this->order('banktransfer', '000000123', 1)));
    }

    private function order(string $method, string $incrementId, int $storeId): OrderInterface
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getStoreId')->willReturn($storeId);

        return $order;
    }
}
