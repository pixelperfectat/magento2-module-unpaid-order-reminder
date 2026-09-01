<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Service;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRunResultInterface;
use PixelPerfect\UnpaidOrderReminder\Api\ReminderLogRepositoryInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ConfigInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ProviderPoolInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderCriterionInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderRunnerInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderSenderInterface;
use PixelPerfect\UnpaidOrderReminder\Model\Data\ReminderRunResultFactory;
use PixelPerfect\UnpaidOrderReminder\Model\ReminderLogFactory;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\Order\UnpaidCollectionFactory;
use PixelPerfect\UnpaidOrderReminder\Service\Criterion\IsPendingPayment;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Selects unpaid orders, resolves each one's payment instructions, sends the reminder, and records
 * that it went.
 *
 * This class owns the whole failure policy (spec §13):
 * - No instructions means no mail and no log row: the order stays eligible for the next run.
 * - The log row is written only after the transport accepted the message.
 * - The order state is re-read immediately before sending, so a payment that arrived mid-run is
 *   never mailed a pointless reminder. "Still pending" means any state in $pendingStates, not just
 *   STATE_PENDING_PAYMENT - see {@see IsPendingPayment} for why.
 * - An expired payment window is skipped, never mailed.
 * - One failing order never stops the run.
 * - A dry run resolves instructions but neither sends nor logs.
 */
class ReminderRunner implements ReminderRunnerInterface
{
    private const MYSQL_DATETIME = 'Y-m-d H:i:s';

    /**
     * Constructor.
     *
     * @param ConfigInterface $config
     * @param ReminderCriterionInterface $criterion
     * @param UnpaidCollectionFactory $collectionFactory
     * @param ProviderPoolInterface $providerPool
     * @param ReminderSenderInterface $sender
     * @param ReminderLogRepositoryInterface $logRepository
     * @param ReminderLogFactory $logFactory
     * @param ReminderRunResultFactory $resultFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param string[] $pendingStates States treated as "still awaiting payment" by the re-read guard
     *     immediately before sending. Wired to {@see IsPendingPayment::PENDING_STATES} in etc/di.xml
     *     so this list can never drift from the one the selection criterion uses.
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ReminderCriterionInterface $criterion,
        private readonly UnpaidCollectionFactory $collectionFactory,
        private readonly ProviderPoolInterface $providerPool,
        private readonly ReminderSenderInterface $sender,
        private readonly ReminderLogRepositoryInterface $logRepository,
        private readonly ReminderLogFactory $logFactory,
        private readonly ReminderRunResultFactory $resultFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly array $pendingStates = IsPendingPayment::PENDING_STATES
    ) {
    }

    /**
     * Selects every eligible order and sends one reminder for each.
     *
     * @param bool $dryRun decide everything, change nothing
     * @return ReminderRunResultInterface
     */
    public function run(bool $dryRun = false): ReminderRunResultInterface
    {
        if (!$this->config->isEnabled()) {
            return $this->resultFactory->create();
        }

        $collection = $this->collectionFactory->create();
        $this->criterion->apply($collection, null);

        $sent = [];
        $skipped = [];

        foreach ($collection->getItems() as $order) {
            /** @var OrderInterface $order */
            $outcome = $this->processOrder($order, $dryRun);
            if ($outcome['reason'] === null) {
                $sent[] = $outcome;
            } else {
                $skipped[] = $outcome;
            }
        }

        return $this->resultFactory->create(['sent' => $sent, 'skipped' => $skipped]);
    }

    /**
     * Decides and, unless it is a dry run, acts on one order.
     *
     * Returns the row for the result, with a null reason when the reminder went out (or, on a dry
     * run, would have gone out).
     *
     * @param OrderInterface $order
     * @param bool $dryRun
     * @return array<string, mixed>
     */
    private function processOrder(OrderInterface $order, bool $dryRun): array
    {
        $orderId = (int)$order->getEntityId();
        $payment = $order->getPayment();
        $method = $payment === null ? '' : (string)$payment->getMethod();

        $row = [
            'order_id' => $orderId,
            'increment_id' => (string)$order->getIncrementId(),
            'payment_method' => $method,
            'expires_at' => null,
            'reason' => null,
        ];

        $rules = $this->config->getRules((int)$order->getStoreId());
        if (!isset($rules[$method])) {
            return ['reason' => 'no_rule'] + $row;
        }

        // The selection ran as one query; a long run can outlive its results. Re-reading here is the
        // only thing standing between a payment that arrived mid-run and a pointless reminder.
        $current = $this->currentOrder($order, $orderId);
        if (!in_array($current->getState(), $this->pendingStates, true)) {
            return ['reason' => 'no_longer_pending'] + $row;
        }

        // Stamped the moment this cycle confirmed the order was still unpaid, not after the send:
        // a provider's instructions lookup can be a live call to the payment gateway itself (the
        // Mollie companion package does exactly that), so the gap between this check and the send
        // is not free. Every early return below this point still returns before any row is written,
        // so an unused timestamp costs nothing.
        $sentAt = (string)$this->dateTime->gmtDate(self::MYSQL_DATETIME);

        $provider = $this->providerPool->getProvider($method);
        if ($provider === null) {
            return ['reason' => 'no_provider'] + $row;
        }

        try {
            $instructions = $provider->forOrder($current);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'UnpaidOrderReminder: instructions lookup failed for order %d: %s',
                $orderId,
                $e->getMessage()
            ));

            return ['reason' => 'provider_error'] + $row;
        }

        if ($instructions === null) {
            // No log row: the order stays eligible and is retried on the next run. Writing the row
            // here would silently consume the order's one and only reminder because a gateway was
            // briefly down.
            $this->logger->warning(sprintf(
                'UnpaidOrderReminder: no payment instructions available for order %d; skipped.',
                $orderId
            ));

            return ['reason' => 'no_instructions'] + $row;
        }

        $expiresAt = $instructions->getExpiresAt();
        $row['expires_at'] = $expiresAt;

        if ($expiresAt !== null) {
            $expiresAtTimestamp = $this->expiresAtTimestamp($expiresAt);
            if ($expiresAtTimestamp === null) {
                // Treated as "never expires" below; the warning is only so this is visible if a
                // provider ever hands back something unparseable.
                $this->logger->warning(sprintf(
                    'UnpaidOrderReminder: could not parse expires_at "%s" for order %d; treating as'
                    . ' not expired.',
                    $expiresAt,
                    $orderId
                ));
            } elseif ($expiresAtTimestamp <= $this->dateTime->gmtTimestamp()) {
                // A shopper cannot act on a window that has already closed; telling them so is worse
                // than silence.
                return ['reason' => 'expired'] + $row;
            }
        }

        if ($dryRun) {
            return $row;
        }

        try {
            $this->sender->send($current, $instructions, $rules[$method]);
        } catch (Throwable $e) {
            // A failed send must leave the order eligible: no log row.
            $this->logger->warning(sprintf(
                'UnpaidOrderReminder: sending failed for order %d: %s',
                $orderId,
                $e->getMessage()
            ));

            return ['reason' => 'send_failed'] + $row;
        }

        try {
            $log = $this->logFactory->create();
            $log->setOrderId($orderId)
                ->setStoreId((int)$current->getStoreId())
                ->setPaymentMethod($method)
                ->setSentAt($sentAt)
                ->setExpiresAt($expiresAt)
                ->setGrandTotal((float)$current->getGrandTotal());
            $this->logRepository->save($log);
        } catch (Throwable $e) {
            // The mail is already gone. Losing the row would let the next run send a second one, so
            // this is loud: error-level, not a skip.
            $this->logger->error(sprintf(
                'UnpaidOrderReminder: reminder for order %d was SENT but its log row failed to save: %s',
                $orderId,
                $e->getMessage()
            ));
        }

        return $row;
    }

    /**
     * Parse a provider's deadline into a UTC timestamp, or null when it cannot be parsed.
     *
     * The value object contract is UTC 'Y-m-d H:i:s', which carries no offset. strtotime() would
     * read it in PHP's default timezone instead, so on a shop whose PHP is set to local time the
     * comparison against gmtTimestamp() was wrong by that offset - an order counted as expired
     * hours early, or reminded hours after its window closed.
     *
     * @param string $expiresAt
     * @return int|null
     */
    private function expiresAtTimestamp(string $expiresAt): ?int
    {
        try {
            return (new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Re-reads an order through the repository immediately before acting on it.
     *
     * Falls back to the collection's own order when the repository cannot re-read it, so a deleted
     * or unreadable order is judged on what we already hold rather than aborting the run.
     *
     * @param OrderInterface $order
     * @param int $orderId
     * @return OrderInterface
     */
    private function currentOrder(OrderInterface $order, int $orderId): OrderInterface
    {
        try {
            $current = $this->orderRepository->get($orderId);
        } catch (Throwable $e) {
            // Silence here would be dangerous: if the repository were ever broken, every order in
            // the run would quietly fall back to stale data with no operator signal at all.
            $this->logger->warning(sprintf(
                'UnpaidOrderReminder: could not re-read order %d, falling back to the selected row: %s',
                $orderId,
                $e->getMessage()
            ));

            return $order;
        }

        return $current instanceof OrderInterface ? $current : $order;
    }
}
