<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderE2e\Console\Command;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use PixelPerfect\UnpaidOrderReminder\Api\ReminderLogRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Removes everything the end-to-end suite created.
 *
 * It only ever deletes an order whose customer email is on a reserved domain. That rule is the
 * reason the suite is safe to run against a database copied from production.
 */
class ResetFixturesCommand extends Command
{
    private const NAME = 'pixelperfect:unpaidorder:e2e-reset';

    /**
     * Reserved domains, per RFC 2606. Nothing outside this list is ever deleted.
     */
    private const RESERVED_DOMAINS = ['@example.com', '@example.org', '@example.net', '@example.test'];

    /**
     * The quote table's own email column. CartInterface declares no constant for it.
     */
    private const QUOTE_CUSTOMER_EMAIL = 'customer_email';

    /**
     * @param State $appState
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderResource $orderResource
     * @param CartRepositoryInterface $quoteRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ReminderLogRepositoryInterface $reminderLogRepository
     * @param Filesystem $filesystem
     * @param Registry $registry
     * @param string|null $name
     */
    public function __construct(
        private readonly State $appState,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderResource $orderResource,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ReminderLogRepositoryInterface $reminderLogRepository,
        private readonly Filesystem $filesystem,
        private readonly Registry $registry,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Declare the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Delete every order, reminder row and captured mail the suite created.');
        parent::configure();
    }

    /**
     * Delete the fixture state.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->appState->getMode() !== State::MODE_DEVELOPER) {
            $output->writeln('<error>This command runs in developer mode only.</error>');
            return Command::FAILURE;
        }

        $orders = 0;
        $reminders = 0;
        $quotes = 0;

        // The core refuses to delete a sales model unless the caller has declared the secure area,
        // which normally only the admin does.
        $this->registry->register('isSecureArea', true);

        try {
            foreach (self::RESERVED_DOMAINS as $domain) {
                $criteria = $this->searchCriteriaBuilder
                    ->addFilter(OrderInterface::CUSTOMER_EMAIL, '%' . $domain, 'like')
                    ->create();
                foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
                    if (!$order instanceof Order) {
                        throw new LocalizedException(__('A listed order is not an order model and cannot be deleted.'));
                    }
                    if ($this->reminderLogRepository->deleteByOrderId((int)$order->getEntityId())) {
                        $reminders++;
                    }
                    $this->orderResource->delete($order);
                    $orders++;
                }
            }

            // Every placed order leaves its quote behind, and a run that failed halfway leaves one
            // with no order at all. Both are fixture rows and both are addressed to a reserved domain.
            foreach (self::RESERVED_DOMAINS as $domain) {
                $criteria = $this->searchCriteriaBuilder
                    ->addFilter(self::QUOTE_CUSTOMER_EMAIL, '%' . $domain, 'like')
                    ->create();
                foreach ($this->quoteRepository->getList($criteria)->getItems() as $quote) {
                    $this->quoteRepository->delete($quote);
                    $quotes++;
                }
            }

            $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            if ($directory->isDirectory('tmp/e2e')) {
                $directory->delete('tmp/e2e');
            }
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            $this->registry->unregister('isSecureArea');
        }

        $output->writeln(sprintf(
            'Removed %d orders, %d reminder rows, %d quotes, and all captured mail.',
            $orders,
            $reminders,
            $quotes
        ));

        return Command::SUCCESS;
    }
}
