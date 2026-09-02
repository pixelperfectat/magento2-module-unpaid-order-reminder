<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderE2e\Console\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock;
use Magento\Framework\App\Area;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Places unpaid fixture orders for the end-to-end suite.
 *
 * It refuses to run outside developer mode, because it writes orders into whatever database it
 * finds, and a staging or production database holds real customers.
 */
class CreateFixtureOrdersCommand extends Command
{
    private const NAME = 'pixelperfect:unpaidorder:e2e-create-orders';
    private const OPTION_COUNT = 'count';
    private const OPTION_METHOD = 'method';
    private const OPTION_STORE = 'store';
    private const OPTION_AGE_DAYS = 'age-days';
    private const OPTION_SKU = 'sku';
    private const OPTION_EMAIL = 'email';
    private const OPTION_POSTCODE = 'postcode';

    /**
     * The installation's own default country. A hard-coded one is rejected by a shop that does not
     * ship there, and the fixture would then fail for a reason unrelated to the code under test.
     */
    private const XML_PATH_DEFAULT_COUNTRY = 'general/country/default';

    /**
     * @param State $appState
     * @param AreaList $areaList
     * @param QuoteFactory $quoteFactory
     * @param CartRepositoryInterface $quoteRepository
     * @param CartManagementInterface $cartManagement
     * @param ProductRepositoryInterface $productRepository
     * @param ProductCollectionFactory $productCollectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderResource $orderResource
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param DateTime $dateTime
     * @param Stock $stockHelper
     * @param string|null $name
     */
    public function __construct(
        private readonly State $appState,
        private readonly AreaList $areaList,
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderResource $orderResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
        private readonly Stock $stockHelper,
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
            ->setDescription('Place unpaid fixture orders for the end-to-end suite.')
            ->addOption(self::OPTION_COUNT, null, InputOption::VALUE_REQUIRED, 'How many orders', '1')
            ->addOption(self::OPTION_METHOD, null, InputOption::VALUE_REQUIRED, 'Payment method code', 'checkmo')
            ->addOption(
                self::OPTION_STORE,
                null,
                InputOption::VALUE_REQUIRED,
                'Store id (default: the default store view)'
            )
            ->addOption(self::OPTION_AGE_DAYS, null, InputOption::VALUE_REQUIRED, 'Backdate created_at by days', '0')
            ->addOption(self::OPTION_SKU, null, InputOption::VALUE_REQUIRED, 'Product SKU to buy')
            ->addOption(self::OPTION_EMAIL, null, InputOption::VALUE_REQUIRED, 'Guest email', 'jane.doe@example.com')
            ->addOption(self::OPTION_POSTCODE, null, InputOption::VALUE_REQUIRED, 'Fixture postcode', '1010');
        parent::configure();
    }

    /**
     * Place the orders and print one increment id per line.
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

        $email = (string)$input->getOption(self::OPTION_EMAIL);
        if (!str_ends_with($email, '@example.com')
            && !str_ends_with($email, '@example.org')
            && !str_ends_with($email, '@example.net')
            && !str_ends_with($email, '@example.test')
        ) {
            $output->writeln('<error>The fixture email must use a reserved domain.</error>');
            return Command::FAILURE;
        }

        $storeOption = $input->getOption(self::OPTION_STORE);
        $method = (string)$input->getOption(self::OPTION_METHOD);
        $ageDays = (int)$input->getOption(self::OPTION_AGE_DAYS);
        $count = max(1, (int)$input->getOption(self::OPTION_COUNT));

        try {
            $this->ensureFrontendArea();
            $storeId = $storeOption !== null
                ? (int)$storeOption
                : (int)$this->storeManager->getDefaultStoreView()->getId();

            $sku = (string)($input->getOption(self::OPTION_SKU) ?? '');
            $sku = $sku !== '' ? $sku : $this->firstSalableSku($storeId);
            $postcode = (string)$input->getOption(self::OPTION_POSTCODE);
            for ($i = 0; $i < $count; $i++) {
                $output->writeln($this->placeOne($storeId, $method, $ageDays, $email, $sku, $postcode));
            }
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            if ($output->isVerbose()) {
                $output->writeln($exception->getTraceAsString());
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Run in the frontend area, so placement takes the same path a shopper takes.
     *
     * @return void
     * @throws LocalizedException
     */
    private function ensureFrontendArea(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
            // The area code alone does not load the area's own di.xml, so placement would otherwise
            // run against the console's object configuration rather than the storefront's.
            $this->areaList->getArea(Area::AREA_FRONTEND)->load(Area::PART_CONFIG);
        }
    }

    /**
     * Place one order and return its increment id.
     *
     * @param int $storeId
     * @param string $method
     * @param int $ageDays
     * @param string $email
     * @param string $sku
     * @param string $postcode
     * @return string
     * @throws LocalizedException
     */
    private function placeOne(
        int $storeId,
        string $method,
        int $ageDays,
        string $email,
        string $sku,
        string $postcode
    ): string {
        $product = $this->productRepository->get($sku, false, $storeId);
        if (!$product instanceof Product) {
            throw new LocalizedException(__('The fixture product "%1" is not a catalog product model.', $sku));
        }

        $quote = $this->quoteFactory->create();
        $quote->setStoreId($storeId);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail($email);
        $quote->setCheckoutMethod(CartManagementInterface::METHOD_GUEST);
        // addProduct answers with a message instead of throwing, which would leave an empty quote.
        $added = $quote->addProduct($product, 1);
        if (is_string($added)) {
            throw new LocalizedException(__('The fixture product "%1" could not be added: %2', $sku, $added));
        }

        $address = [
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'street' => ['1 Example Street'],
            'city' => 'Example City',
            'postcode' => $postcode,
            'country_id' => $this->defaultCountryId($storeId),
            'telephone' => '0000000000',
            'email' => $email,
        ];
        $quote->getBillingAddress()->addData($address);
        $shipping = $quote->getShippingAddress();
        $shipping->addData($address);
        $shipping->setCollectShippingRates(true)->collectShippingRates();

        $rates = $shipping->getAllShippingRates();
        $code = 'flatrate_flatrate';
        $codes = array_map(static fn ($rate): string => (string)$rate->getCode(), $rates);
        if (!in_array($code, $codes, true)) {
            $code = $codes[0] ?? '';
        }
        if ($code === '') {
            throw new LocalizedException(__('No shipping method is available for the fixture address.'));
        }
        $shipping->setShippingMethod($code);

        $quote->setInventoryProcessed(false);
        $quote->collectTotals();

        // Saved before the payment is touched: on a quote with no id, getPayment() reads an
        // unfiltered payment collection and hands back a payment that belongs to no quote.
        $this->quoteRepository->save($quote);

        // The payment method is set after collectTotals. Setting it before makes the total
        // recalculation drop it, which produces an order with no payment.
        $quote->getPayment()->importData(['method' => $method]);

        $this->quoteRepository->save($quote);
        $orderId = $this->cartManagement->placeOrder((int)$quote->getId());

        $order = $this->orderRepository->get((int)$orderId);
        if ($ageDays > 0) {
            if (!$order instanceof Order) {
                throw new LocalizedException(__('The placed order is not an order model and cannot be backdated.'));
            }
            $order->setCreatedAt($this->dateTime->gmtDate('Y-m-d H:i:s', sprintf('-%d days', $ageDays)));
            $this->orderResource->save($order);
        }

        return (string)$order->getIncrementId();
    }

    /**
     * Read the store's own default country for the fixture address.
     *
     * @param int $storeId
     * @return string
     * @throws LocalizedException
     */
    private function defaultCountryId(int $storeId): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_COUNTRY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $countryId = is_string($value) ? $value : '';
        if ($countryId === '') {
            throw new LocalizedException(__('The store has no default country and the fixture has no address.'));
        }

        return $countryId;
    }

    /**
     * Find a product that can actually be bought, so the fixture does not depend on a fixed SKU.
     *
     * @param int $storeId
     * @return string
     * @throws LocalizedException
     */
    private function firstSalableSku(int $storeId): string
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect('sku')
            ->addAttributeToFilter('type_id', ['eq' => 'simple'])
            ->addAttributeToFilter('status', ['eq' => 1])
            ->addAttributeToFilter('required_options', ['eq' => 0])
            ->setPageSize(1);
        // The core docblock names a link collection, but the method only joins onto a product collection.
        /** @phpstan-ignore-next-line */
        $this->stockHelper->addInStockFilterToCollection($collection);

        $product = $collection->getFirstItem();
        $sku = (string)$product->getSku();
        if ($sku === '') {
            throw new LocalizedException(__('No simple product is available to buy.'));
        }

        return $sku;
    }
}
