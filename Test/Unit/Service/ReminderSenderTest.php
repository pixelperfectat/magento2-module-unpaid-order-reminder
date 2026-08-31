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

    public function testPassesTheOrderAndStoreIdToTheTemplate(): void
    {
        $order = $this->order();

        $this->transportBuilder->expects($this->once())
            ->method('setTemplateVars')
            ->with($this->callback(static function (array $vars) use ($order): bool {
                return $vars['order'] === $order
                    && $vars['store_id'] === 7;
            }))
            ->willReturnSelf();

        $this->sender->send($order, $this->instructions(), $this->rule('tpl'));
    }

    /**
     * Magento's StrictResolver::shouldHandleDataAccess() only allows property/method access on a
     * DataObject, an AbstractTemplate, or an array. PaymentInstructions is a plain typed value
     * object and satisfies none of those, so instructions.getXxx() would silently resolve to null
     * in the template. Every field the template needs is therefore flattened into its own scalar
     * variable here - not the instructions object itself, which is dropped from the variables.
     */
    public function testFlattensPaymentInstructionsIntoScalarTemplateVariables(): void
    {
        $instructions = $this->createMock(PaymentInstructionsInterface::class);
        $instructions->method('getInstructionsHtml')->willReturn('<p>Pay within 7 days.</p>');
        $instructions->method('getBankName')->willReturn('Example Bank');
        $instructions->method('getBankAccount')->willReturn('AT00 0000 0000 0000 0000');
        $instructions->method('getBankBic')->willReturn('EXAMPLEATXXX');
        $instructions->method('getReference')->willReturn('ORDER-900');
        $instructions->method('getPaymentUrl')->willReturn('https://example.com/pay/900');

        $this->transportBuilder->expects($this->once())
            ->method('setTemplateVars')
            ->with($this->callback(static function (array $vars): bool {
                return !array_key_exists('instructions', $vars)
                    && $vars['instructionsHtml'] === '<p>Pay within 7 days.</p>'
                    && $vars['bankName'] === 'Example Bank'
                    && $vars['bankAccount'] === 'AT00 0000 0000 0000 0000'
                    && $vars['bankBic'] === 'EXAMPLEATXXX'
                    && $vars['paymentReference'] === 'ORDER-900'
                    && $vars['paymentUrl'] === 'https://example.com/pay/900';
            }))
            ->willReturnSelf();

        $this->sender->send($this->order(), $instructions, $this->rule('tpl'));
    }

    /**
     * The cast path a real offline order takes: an offline method's PaymentInstructions leaves
     * every optional field null. Each flattened variable must arrive as an empty string, not
     * null, so the template never has to distinguish "not set" from "not cast".
     */
    public function testFlattensNullInstructionsFieldsToEmptyStrings(): void
    {
        $instructions = $this->createMock(PaymentInstructionsInterface::class);

        $this->transportBuilder->expects($this->once())
            ->method('setTemplateVars')
            ->with($this->callback(static function (array $vars): bool {
                return $vars['instructionsHtml'] === ''
                    && $vars['bankName'] === ''
                    && $vars['bankAccount'] === ''
                    && $vars['bankBic'] === ''
                    && $vars['paymentReference'] === ''
                    && $vars['paymentUrl'] === '';
            }))
            ->willReturnSelf();

        $this->sender->send($this->order(), $instructions, $this->rule('tpl'));
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

    /**
     * The template cannot call hasStructuredBankDetails() directly: Magento's StrictResolver only
     * resolves template method calls whose name starts with "get", so any other method call
     * silently resolves to null and a {{depend}} guarded by it is always false. The flag must
     * therefore be precomputed here and passed as a plain template variable.
     */
    public function testPassesWhetherStructuredBankDetailsAreAvailable(): void
    {
        $instructions = $this->createMock(PaymentInstructionsInterface::class);
        $instructions->method('hasStructuredBankDetails')->willReturn(true);

        $this->transportBuilder->expects($this->once())
            ->method('setTemplateVars')
            ->with($this->callback(static fn (array $vars): bool => $vars['hasBankDetails'] === true))
            ->willReturnSelf();

        $this->sender->send($this->order(), $instructions, $this->rule('tpl'));
    }

    public function testPassesFalseWhenStructuredBankDetailsAreNotAvailable(): void
    {
        $instructions = $this->createMock(PaymentInstructionsInterface::class);
        $instructions->method('hasStructuredBankDetails')->willReturn(false);

        $this->transportBuilder->expects($this->once())
            ->method('setTemplateVars')
            ->with($this->callback(static fn (array $vars): bool => $vars['hasBankDetails'] === false))
            ->willReturnSelf();

        $this->sender->send($this->order(), $instructions, $this->rule('tpl'));
    }

    /**
     * Closes the gap named in code review: nothing previously proved the template and the sender
     * agree on variable names. Every plain (non-dotted) variable the template references - via
     * {{var x}}, {{depend x}}, or $x used as a directive argument - must be a key the sender
     * actually passes, or the template would silently render nothing for it.
     */
    public function testEveryPlainTemplateVariableIsPassedBySender(): void
    {
        $path = __DIR__ . '/../../../view/frontend/email/unpaid_order_reminder.html';
        $this->assertFileExists($path);
        $contents = (string)file_get_contents($path);

        $names = [];
        preg_match_all('/\{\{var\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', $contents, $matches);
        $names = array_merge($names, $matches[1]);
        preg_match_all('/\{\{depend\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', $contents, $matches);
        $names = array_merge($names, $matches[1]);
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)(\.[A-Za-z_][A-Za-z0-9_]*)?/', $contents, $matches);
        foreach ($matches[1] as $index => $name) {
            if ($matches[2][$index] === '') {
                $names[] = $name;
            }
        }
        $names = array_unique($names);

        $this->assertNotEmpty($names, 'Expected the template to reference at least one plain variable.');

        // A dedicated mock, rather than $this->transportBuilder: setUp() already registers an
        // unconstrained setTemplateVars() stub on that one, and PHPUnit resolves a stubbed
        // return value from the first matching stub, so a second stub added here would never
        // actually run.
        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setFromByScope')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();
        $transportBuilder->method('addBcc')->willReturnSelf();
        $transportBuilder->method('getTransport')->willReturn($this->transport);

        $vars = null;
        $transportBuilder->method('setTemplateVars')
            ->willReturnCallback(function (array $templateVars) use (&$vars, $transportBuilder) {
                $vars = $templateVars;

                return $transportBuilder;
            });

        $sender = new ReminderSender(
            $transportBuilder,
            $this->emulation,
            $this->config,
            $this->senderResolver,
            $this->priceCurrency,
            $this->timezone
        );

        $instructions = $this->createMock(PaymentInstructionsInterface::class);
        $sender->send($this->order(), $instructions, $this->rule('tpl'));

        $this->assertIsArray($vars);
        $missing = array_values(array_filter(
            $names,
            static fn (string $name): bool => !array_key_exists($name, $vars)
        ));

        $this->assertSame(
            [],
            $missing,
            'The template references a variable the sender never passes: ' . implode(', ', $missing)
        );
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
