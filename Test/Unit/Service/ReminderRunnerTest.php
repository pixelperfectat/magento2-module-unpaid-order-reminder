<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\PaymentInstructionsInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRuleInterface;
use PixelPerfect\UnpaidOrderReminder\Api\ReminderLogRepositoryInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ConfigInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\PaymentInstructionsProviderInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ProviderPoolInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderCriterionInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderSenderInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Data\ReminderRunResult;
use PixelPerfect\UnpaidOrderReminder\Model\Data\ReminderRunResultFactory;
use PixelPerfect\UnpaidOrderReminder\Model\ReminderLog;
use PixelPerfect\UnpaidOrderReminder\Model\ReminderLogFactory;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\Order\UnpaidCollection;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\Order\UnpaidCollectionFactory;
use PixelPerfect\UnpaidOrderReminder\Service\Criterion\IsPendingPayment;
use PixelPerfect\UnpaidOrderReminder\Service\ReminderRunner;

class ReminderRunnerTest extends TestCase
{
    /** @var ConfigInterface|MockObject */
    private $config;
    /** @var ProviderPoolInterface|MockObject */
    private $pool;
    /** @var PaymentInstructionsProviderInterface|MockObject */
    private $provider;
    /** @var ReminderSenderInterface|MockObject */
    private $sender;
    /** @var ReminderLogRepositoryInterface|MockObject */
    private $logRepository;
    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepository;
    /** @var UnpaidCollection|MockObject */
    private $collection;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getRules')->willReturn(['banktransfer' => $this->rule()]);

        $this->provider = $this->createMock(PaymentInstructionsProviderInterface::class);
        $this->provider->method('forOrder')->willReturn($this->instructions('2099-01-01 00:00:00'));

        $this->pool = $this->createMock(ProviderPoolInterface::class);
        $this->pool->method('getProvider')->willReturn($this->provider);

        $this->sender = $this->createMock(ReminderSenderInterface::class);
        $this->logRepository = $this->createMock(ReminderLogRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);

        $this->collection = $this->createMock(UnpaidCollection::class);
        $this->collection->method('getItems')->willReturn([$this->order()]);
    }

    public function testSendsAReminderAndLogsIt(): void
    {
        $this->sender->expects($this->once())->method('send');
        $this->logRepository->expects($this->once())->method('save');

        $result = $this->runner()->run();

        $this->assertSame(1, $result->getSentCount());
        $this->assertSame(0, $result->getSkippedCount());
    }

    public function testSendsNothingWhileTheModuleIsDisabled(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(false);
        $this->sender->expects($this->never())->method('send');

        $result = $this->runner()->run();

        $this->assertSame(0, $result->getSentCount());
    }

    /**
     * A dry run must exercise the same decisions, so what it reports is what a real run would do.
     */
    public function testADryRunResolvesInstructionsButNeitherSendsNorLogs(): void
    {
        $this->provider->expects($this->once())->method('forOrder');
        $this->sender->expects($this->never())->method('send');
        $this->logRepository->expects($this->never())->method('save');

        $result = $this->runner()->run(true);

        $this->assertSame(1, $result->getSentCount());
    }

    /**
     * Spec §13: a mail with no instructions is never sent, and no log row is written, so the order
     * is retried on the next run.
     */
    public function testSkipsAndDoesNotLogWhenTheProviderReturnsNull(): void
    {
        $this->provider = $this->createMock(PaymentInstructionsProviderInterface::class);
        $this->provider->method('forOrder')->willReturn(null);
        $this->pool = $this->createMock(ProviderPoolInterface::class);
        $this->pool->method('getProvider')->willReturn($this->provider);

        $this->sender->expects($this->never())->method('send');
        $this->logRepository->expects($this->never())->method('save');

        $result = $this->runner()->run();

        $this->assertSame(0, $result->getSentCount());
        $this->assertSame(1, $result->getSkippedCount());
        $this->assertSame('no_instructions', $result->getSkipped()[0]['reason']);
    }

    public function testSkipsWhenTheProviderThrows(): void
    {
        $this->provider = $this->createMock(PaymentInstructionsProviderInterface::class);
        $this->provider->method('forOrder')->willThrowException(new \RuntimeException('gateway down'));
        $this->pool = $this->createMock(ProviderPoolInterface::class);
        $this->pool->method('getProvider')->willReturn($this->provider);

        $this->logRepository->expects($this->never())->method('save');

        $result = $this->runner()->run();

        $this->assertSame(1, $result->getSkippedCount());
        $this->assertSame('provider_error', $result->getSkipped()[0]['reason']);
    }

    /**
     * A shopper cannot act on a window that has already closed, and telling them so is worse than
     * silence.
     */
    public function testSkipsAnOrderWhosePaymentWindowHasClosed(): void
    {
        $this->provider = $this->createMock(PaymentInstructionsProviderInterface::class);
        $this->provider->method('forOrder')->willReturn($this->instructions('2000-01-01 00:00:00'));
        $this->pool = $this->createMock(ProviderPoolInterface::class);
        $this->pool->method('getProvider')->willReturn($this->provider);

        $this->sender->expects($this->never())->method('send');

        $result = $this->runner()->run();

        $this->assertSame(1, $result->getSkippedCount());
        $this->assertSame('expired', $result->getSkipped()[0]['reason']);
    }

    public function testSendsWhenThePaymentNeverExpires(): void
    {
        $this->provider = $this->createMock(PaymentInstructionsProviderInterface::class);
        $this->provider->method('forOrder')->willReturn($this->instructions(null));
        $this->pool = $this->createMock(ProviderPoolInterface::class);
        $this->pool->method('getProvider')->willReturn($this->provider);

        $this->sender->expects($this->once())->method('send');

        $this->assertSame(1, $this->runner()->run()->getSentCount());
    }

    /**
     * A long run must not mail an order that was paid while the run was in progress.
     */
    public function testRereadsTheOrderStateAndSkipsOneThatWasPaidDuringTheRun(): void
    {
        $fresh = $this->createMock(Order::class);
        $fresh->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->orderRepository->method('get')->willReturn($fresh);

        $this->sender->expects($this->never())->method('send');

        $result = $this->runner()->run();

        $this->assertSame(1, $result->getSkippedCount());
        $this->assertSame('no_longer_pending', $result->getSkipped()[0]['reason']);
    }

    /**
     * Spec regression: Magento's offline payment methods (banktransfer, checkmo, cashondelivery,
     * purchaseorder) never reach STATE_PENDING_PAYMENT - they sit in STATE_NEW. The re-read guard must
     * accept that state too, or every offline order is rejected as "no longer pending" and nobody who
     * paid offline is ever reminded.
     */
    public function testSendsAReminderForAnOrderStillInStateNew(): void
    {
        $fresh = $this->createMock(Order::class);
        $fresh->method('getState')->willReturn(Order::STATE_NEW);
        $fresh->method('getStoreId')->willReturn(1);
        $fresh->method('getGrandTotal')->willReturn(99.9);
        $this->orderRepository->method('get')->willReturn($fresh);

        $this->sender->expects($this->once())->method('send');

        $result = $this->runner()->run();

        $this->assertSame(1, $result->getSentCount());
        $this->assertSame(0, $result->getSkippedCount());
    }

    /**
     * The re-read guard's state list is a constructor argument (wired to
     * IsPendingPayment::PENDING_STATES in etc/di.xml) rather than a hard-coded literal, so it must
     * actually be consulted rather than merely defaulted.
     */
    public function testUsesTheInjectedPendingStatesListRatherThanAHardCodedOne(): void
    {
        $this->sender->expects($this->never())->method('send');

        $result = $this->runner(null, null, null, ['awaiting_transfer'])->run();

        $this->assertSame(1, $result->getSkippedCount());
        $this->assertSame('no_longer_pending', $result->getSkipped()[0]['reason']);
    }

    /**
     * Spec §13: the log row is written only after the transport accepted the message, so a failed
     * send can never silently consume an order's one reminder.
     */
    public function testWritesNoLogRowWhenSendingFails(): void
    {
        $this->sender->method('send')->willThrowException(new MailException(__('refused')));
        $this->logRepository->expects($this->never())->method('save');

        $result = $this->runner()->run();

        $this->assertSame(0, $result->getSentCount());
        $this->assertSame('send_failed', $result->getSkipped()[0]['reason']);
    }

    public function testOneFailingOrderDoesNotStopTheRest(): void
    {
        $this->collection = $this->createMock(UnpaidCollection::class);
        $this->collection->method('getItems')->willReturn([$this->order(900), $this->order(901)]);

        $this->sender->method('send')->willReturnOnConsecutiveCalls(
            $this->throwException(new MailException(__('refused'))),
            null
        );

        $result = $this->runner()->run();

        $this->assertSame(1, $result->getSentCount());
        $this->assertSame(1, $result->getSkippedCount());
        $this->assertSame(900, $result->getSkipped()[0]['order_id']);
        $this->assertSame('send_failed', $result->getSkipped()[0]['reason']);
        $this->assertSame(901, $result->getSent()[0]['order_id']);
    }

    /**
     * Spec §13: the mail is already gone once the transport accepts it, so losing the log row must
     * be loud (error-level) and must never turn a sent reminder into a skip - that would let the
     * next run send a second one.
     */
    public function testLogsAnErrorAndStillCountsAsSentWhenTheLogRowFailsToSave(): void
    {
        $this->logRepository->method('save')->willThrowException(
            new CouldNotSaveException(__('duplicate order_id'))
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('order 900'));
        $logger->expects($this->never())->method('warning');

        $result = $this->runner(null, $logger)->run();

        $this->assertSame(1, $result->getSentCount());
        $this->assertSame(0, $result->getSkippedCount());
        $this->assertSame(900, $result->getSent()[0]['order_id']);
    }

    /**
     * currentOrder()'s fallback must not be silent: an operator needs to know the re-read is not
     * happening, or the freshness guard is disabled for the whole run with no signal at all.
     */
    public function testLogsAWarningAndFallsBackWhenTheOrderCannotBeReread(): void
    {
        $this->orderRepository->method('get')->willThrowException(
            new \RuntimeException('database connection lost')
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->logicalAnd(
                $this->stringContains('order 900'),
                $this->stringContains('database connection lost')
            )
        );

        $result = $this->runner(null, $logger)->run();

        // The fallback still lets the run process the order on the data already in hand.
        $this->assertSame(1, $result->getSentCount());
        $this->assertSame(0, $result->getSkippedCount());
    }

    /**
     * sent_at must be captured the moment this cycle confirms the order is still unpaid - before the
     * instructions lookup and before the send, not after either of them. A provider's lookup can be
     * a live call to the payment gateway itself (the Mollie companion package does exactly that), so
     * that window is not free; a payment webhook racing it must never be able to land with an
     * updated_at earlier than sent_at. See
     * ReminderEfficacyReaderTest::testTheThreeGroupsSumToTheRemindedCountIncludingAnEqualTimestamp()
     * for why that ordering is what makes the efficacy partition exhaustive.
     */
    public function testStampsSentAtBeforeTheInstructionsLookupAndTheSend(): void
    {
        $order = [];
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturnCallback(static function () use (&$order): string {
            $order[] = 'stamped';
            return '2026-08-31 10:00:00';
        });
        $dateTime->method('gmtTimestamp')->willReturn(1788170400);

        $this->provider = $this->createMock(PaymentInstructionsProviderInterface::class);
        $this->provider->method('forOrder')->willReturnCallback(
            function () use (&$order): PaymentInstructionsInterface {
                $order[] = 'forOrder';
                return $this->instructions('2099-01-01 00:00:00');
            }
        );
        $this->pool = $this->createMock(ProviderPoolInterface::class);
        $this->pool->method('getProvider')->willReturn($this->provider);

        $this->sender->method('send')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'sent';
        });

        $this->runner(null, null, $dateTime)->run();

        $this->assertSame(['stamped', 'forOrder', 'sent'], $order);
    }

    public function testFreezesTheOrderTotalAndExpiryOnTheLogRow(): void
    {
        $log = $this->createMock(ReminderLog::class);
        $log->expects($this->once())->method('setGrandTotal')->with(99.9)->willReturnSelf();
        $log->expects($this->once())->method('setExpiresAt')->with('2099-01-01 00:00:00')->willReturnSelf();
        $log->method('setOrderId')->willReturnSelf();
        $log->method('setStoreId')->willReturnSelf();
        $log->method('setPaymentMethod')->willReturnSelf();
        $log->method('setSentAt')->willReturnSelf();

        $this->runner($log)->run();
    }

    /**
     * @param ReminderLog|null $log
     * @param LoggerInterface|null $logger
     * @param DateTime|null $dateTime
     * @param string[]|null $pendingStates null keeps the runner's own default
     * @return ReminderRunner
     */
    private function runner(
        ?ReminderLog $log = null,
        ?LoggerInterface $logger = null,
        ?DateTime $dateTime = null,
        ?array $pendingStates = null
    ): ReminderRunner {
        $criterion = $this->createMock(ReminderCriterionInterface::class);

        $collectionFactory = $this->createMock(UnpaidCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->collection);

        $logFactory = $this->createMock(ReminderLogFactory::class);
        $logFactory->method('create')->willReturn($log ?? $this->passthroughLog());

        $resultFactory = $this->createMock(ReminderRunResultFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            static fn (array $data = []): ReminderRunResult => new ReminderRunResult(...$data)
        );

        if ($dateTime === null) {
            $dateTime = $this->createMock(DateTime::class);
            $dateTime->method('gmtDate')->willReturn('2026-08-31 10:00:00');
            $dateTime->method('gmtTimestamp')->willReturn(1788170400);
        }

        return new ReminderRunner(
            $this->config,
            $criterion,
            $collectionFactory,
            $this->pool,
            $this->sender,
            $this->logRepository,
            $logFactory,
            $resultFactory,
            $this->orderRepository,
            $dateTime,
            $logger ?? $this->createMock(LoggerInterface::class),
            $pendingStates ?? IsPendingPayment::PENDING_STATES
        );
    }

    private function passthroughLog(): ReminderLog
    {
        $log = $this->createMock(ReminderLog::class);
        foreach (['setOrderId', 'setStoreId', 'setPaymentMethod', 'setSentAt', 'setExpiresAt', 'setGrandTotal'] as $setter) {
            $log->method($setter)->willReturnSelf();
        }

        return $log;
    }

    private function rule(): ReminderRuleInterface
    {
        $rule = $this->createMock(ReminderRuleInterface::class);
        $rule->method('getPaymentMethod')->willReturn('banktransfer');
        $rule->method('getDelayDays')->willReturn(5);
        $rule->method('getEmailTemplate')->willReturn('unpaid_order_reminder_default');

        return $rule;
    }

    private function instructions(?string $expiresAt): PaymentInstructionsInterface
    {
        $instructions = $this->createMock(PaymentInstructionsInterface::class);
        $instructions->method('getExpiresAt')->willReturn($expiresAt);

        return $instructions;
    }

    private function order(int $entityId = 900): OrderInterface
    {
        $payment = $this->createMock(\Magento\Sales\Api\Data\OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn('banktransfer');

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn('0000' . $entityId);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(99.9);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);

        return $order;
    }
}
