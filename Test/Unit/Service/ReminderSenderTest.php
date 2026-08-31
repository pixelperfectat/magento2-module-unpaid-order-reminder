<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service;

use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\SenderResolverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Data\PaymentInstructionsInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRuleInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ConfigInterface;
use PixelPerfect\UnpaidOrderReminder\Service\ReminderSender;

class ReminderSenderTest extends TestCase
{
    /** @var TransportBuilder|MockObject */
    private $transportBuilder;
    /** @var TransportInterface|MockObject */
    private $transport;
    /** @var Emulation|MockObject */
    private $emulation;
    /** @var ConfigInterface|MockObject */
    private $config;
    /** @var SenderResolverInterface|MockObject */
    private $senderResolver;
    /** @var PriceCurrencyInterface|MockObject */
    private $priceCurrency;
    /** @var TimezoneInterface|MockObject */
    private $timezone;
    private ReminderSender $sender;

    protected function setUp(): void
    {
        $this->transport = $this->createMock(TransportInterface::class);

        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        $this->transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $this->transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $this->transportBuilder->method('setTemplateVars')->willReturnSelf();
        $this->transportBuilder->method('setFromByScope')->willReturnSelf();
        $this->transportBuilder->method('addTo')->willReturnSelf();
        $this->transportBuilder->method('addBcc')->willReturnSelf();
        $this->transportBuilder->method('getTransport')->willReturn($this->transport);

        $this->emulation = $this->createMock(Emulation::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('getSender')->willReturn('sales');
        $this->config->method('getBcc')->willReturn([]);

        $this->senderResolver = $this->createMock(SenderResolverInterface::class);
        $this->senderResolver->method('resolve')->willReturn(
            ['email' => 'shop@example.com', 'name' => 'Example Shop']
        );

        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $this->timezone = $this->createMock(TimezoneInterface::class);

        $this->sender = new ReminderSender(
            $this->transportBuilder,
            $this->emulation,
            $this->config,
            $this->senderResolver,
            $this->priceCurrency,
            $this->timezone
        );
    }

    public function testSendsTheConfiguredTemplateToTheOrdersCustomer(): void
    {
        $this->transportBuilder->expects($this->once())
            ->method('setTemplateIdentifier')
            ->with('unpaid_order_reminder_offline')
            ->willReturnSelf();
        $this->transportBuilder->expects($this->once())
            ->method('addTo')
            ->with('jane.doe@example.com', 'Jane Doe')
            ->willReturnSelf();
        $this->transport->expects($this->once())->method('sendMessage');

        $this->sender->send($this->order(), $this->instructions(), $this->rule('unpaid_order_reminder_offline'));
    }

    /**
     * A cron has no storefront request. Without emulation the locale and every URL resolve in admin
     * context, which is how mail from cron ends up untranslated with broken links.
     */
    public function testRunsInsideStoreEmulationAndAlwaysStopsIt(): void
    {
        $this->emulation->expects($this->once())->method('startEnvironmentEmulation')
            ->with(7, \Magento\Framework\App\Area::AREA_FRONTEND, true);
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');

        $this->sender->send($this->order(), $this->instructions(), $this->rule('tpl'));
    }

    public function testStopsEmulationEvenWhenSendingThrows(): void
    {
        $this->transport->method('sendMessage')->willThrowException(new MailException(__('refused')));
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');

        $this->expectException(MailException::class);

        $this->sender->send($this->order(), $this->instructions(), $this->rule('tpl'));
    }

    public function testPassesTheOrderInstructionsAndStoreToTheTemplate(): void
    {
        $instructions = $this->instructions();
        $order = $this->order();

        $this->transportBuilder->expects($this->once())
            ->method('setTemplateVars')
            ->with($this->callback(static function (array $vars) use ($order, $instructions): bool {
                return $vars['order'] === $order
                    && $vars['instructions'] === $instructions
                    && $vars['store_id'] === 7;
            }))
            ->willReturnSelf();

        $this->sender->send($order, $instructions, $this->rule('tpl'));
    }

    public function testAddsEveryConfiguredBccAddress(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('getSender')->willReturn('sales');
        $this->config->method('getBcc')->willReturn(['ops@example.com', 'finance@example.com']);

        $sender = new ReminderSender(
            $this->transportBuilder,
            $this->emulation,
            $this->config,
            $this->senderResolver,
            $this->priceCurrency,
            $this->timezone
        );

        $this->transportBuilder->expects($this->once())
            ->method('addBcc')
            ->with(['ops@example.com', 'finance@example.com'])
            ->willReturnSelf();

        $sender->send($this->order(), $this->instructions(), $this->rule('tpl'));
    }

    public function testRefusesToSendToAnOrderWithNoEmailAddress(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomerEmail')->willReturn('');
        $order->method('getStoreId')->willReturn(7);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('has no email address');

        $this->sender->send($order, $this->instructions(), $this->rule('tpl'));
    }

    public function testFormatsTheTotalInTheOrdersCurrency(): void
    {
        $this->priceCurrency->expects($this->once())
            ->method('format')
            ->willReturn('€99.90');

        $this->transportBuilder->expects($this->once())
            ->method('setTemplateVars')
            ->with($this->callback(static fn (array $vars): bool => $vars['formattedTotal'] === '€99.90'))
            ->willReturnSelf();

        $this->sender->send($this->order(), $this->instructions(), $this->rule('tpl'));
    }

    /**
     * The deadline is stored in UTC and read by a shopper on the store's clock.
     */
    public function testLeavesTheDeadlineEmptyWhenThePaymentNeverExpires(): void
    {
        $instructions = $this->createMock(PaymentInstructionsInterface::class);
        $instructions->method('getExpiresAt')->willReturn(null);

        $this->transportBuilder->expects($this->once())
            ->method('setTemplateVars')
            ->with($this->callback(static fn (array $vars): bool => $vars['formattedDeadline'] === ''))
            ->willReturnSelf();

        $this->sender->send($this->order(), $instructions, $this->rule('tpl'));
    }

    private function order(): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomerEmail')->willReturn('jane.doe@example.com');
        $order->method('getCustomerFirstname')->willReturn('Jane');
        $order->method('getCustomerLastname')->willReturn('Doe');
        $order->method('getStoreId')->willReturn(7);
        $order->method('getEntityId')->willReturn(900);

        return $order;
    }

    private function instructions(): PaymentInstructionsInterface
    {
        return $this->createMock(PaymentInstructionsInterface::class);
    }

    private function rule(string $template): ReminderRuleInterface
    {
        $rule = $this->createMock(ReminderRuleInterface::class);
        $rule->method('getEmailTemplate')->willReturn($template);

        return $rule;
    }
}
