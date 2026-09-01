<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Model\Data\ReminderEfficacy;
use PixelPerfect\UnpaidOrderReminder\Model\Data\ReminderEfficacyFactory;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\ReminderLog\Collection;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\ReminderLog\CollectionFactory;
use PixelPerfect\UnpaidOrderReminder\Service\ReminderEfficacyReader;

class ReminderEfficacyReaderTest extends TestCase
{
    /** @var AdapterInterface|MockObject */
    private $connection;
    /** @var Select|MockObject */
    private $select;

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        foreach (['from', 'joinInner', 'where', 'reset'] as $fluent) {
            $this->select->method($fluent)->willReturnSelf();
        }

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('quote')->willReturnCallback(
            static fn (mixed $v): string => "'" . (string)$v . "'"
        );
    }

    public function testCountsAndValuesTheFourGroups(): void
    {
        $this->select->method('columns')->willReturnSelf();
        $this->connection->method('fetchRow')->willReturn([
            'reminded_count' => '10',
            'reminded_value' => '1000.0000',
            'paid_count' => '4',
            'paid_value' => '450.5000',
            'still_unpaid_count' => '2',
            'still_unpaid_value' => '199.5000',
            'expired_count' => '4',
            'expired_value' => '350.0000',
        ]);

        $efficacy = $this->reader()->read();

        $this->assertSame(10, $efficacy->getRemindedCount());
        $this->assertSame(1000.0, $efficacy->getRemindedValue());
        $this->assertSame(4, $efficacy->getPaidCount());
        $this->assertSame(450.5, $efficacy->getPaidValue());
        $this->assertSame(2, $efficacy->getStillUnpaidCount());
        $this->assertSame(4, $efficacy->getExpiredCount());
        $this->assertSame(350.0, $efficacy->getExpiredValue());
    }

    public function testAnEmptyLogReportsZeroesRatherThanNulls(): void
    {
        $this->select->method('columns')->willReturnSelf();
        $this->connection->method('fetchRow')->willReturn(false);

        $efficacy = $this->reader()->read();

        $this->assertSame(0, $efficacy->getRemindedCount());
        $this->assertSame(0.0, $efficacy->getRemindedValue());
        $this->assertSame(0, $efficacy->getPaidCount());
    }

    /**
     * An order paid before the reminder went out was not won by it.
     */
    public function testCreditsOnlyPaymentsThatFollowedTheReminder(): void
    {
        $captured = [];
        $this->select->method('columns')->willReturnCallback(
            function (array $columns) use (&$captured): Select {
                $captured = $columns;
                return $this->select;
            }
        );
        $this->connection->method('fetchRow')->willReturn(false);

        $this->reader()->read();

        $this->assertStringContainsString('so.updated_at >= pp.sent_at', (string)$captured['paid_count']);
    }

    /**
     * The three outcome groups must partition the reminded population: their counts always sum to
     * the reminded count. This executes the reader's own generated SQL condition fragments — not a
     * reimplementation of them — against a fixture that includes an order whose updated_at lands in
     * the very same second as its reminder's sent_at, the boundary the paid/orphan gap closed, and an
     * order in state "new" - the state Magento's offline payment methods actually use instead of
     * "pending_payment". Before the fix, "new" was not excluded from the paid group, so a reminded
     * offline order that was never paid was silently counted as a conversion.
     */
    public function testTheThreeGroupsSumToTheRemindedCountIncludingAnEqualTimestamp(): void
    {
        $captured = [];
        $this->select->method('columns')->willReturnCallback(
            function (array $columns) use (&$captured): Select {
                $captured = $columns;
                return $this->select;
            }
        );
        $this->connection->method('fetchRow')->willReturn(false);
        $this->reader()->read();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The expired branch calls MySQL's UTC_TIMESTAMP(); give SQLite the same fixed "now".
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-08-31 12:00:00', 0);

        $pdo->exec('CREATE TABLE pp (order_id INTEGER, sent_at TEXT, expires_at TEXT)');
        $pdo->exec('CREATE TABLE so (entity_id INTEGER, state TEXT, updated_at TEXT)');
        $insertPp = $pdo->prepare('INSERT INTO pp (order_id, sent_at, expires_at) VALUES (?, ?, ?)');
        $insertSo = $pdo->prepare('INSERT INTO so (entity_id, state, updated_at) VALUES (?, ?, ?)');

        // 1: paid at the exact same second the reminder was stamped - the boundary the first fix
        //    closed.       2: still pending, no expiry.       3: pending, past its expiry.
        // 4: cancelled.
        // 5: paid a second after sent_at was stamped, i.e. landing in the window between the
        //    pending-state check and the send (the instructions lookup and expiry check run in
        //    between). Since sent_at is now captured before that window rather than after it, this
        //    row's updated_at falls after sent_at and lands in paid, not nowhere - the fix in round 2.
        // 6: an offline order, still unpaid, sitting in state "new" - the state Magento's offline
        //    payment methods actually use. Before this fix this row was miscounted as paid, because
        //    "new" was not in the excluded set and its updated_at trivially satisfies ">= sent_at".
        $fixture = [
            [1, '2026-08-01 10:00:00', null, 'processing', '2026-08-01 10:00:00'],
            [2, '2026-08-01 10:00:00', null, 'pending_payment', '2026-08-01 10:00:00'],
            [3, '2026-08-01 10:00:00', '2026-08-05 00:00:00', 'pending_payment', '2026-08-01 10:00:00'],
            [4, '2026-08-01 10:00:00', null, 'canceled', '2026-08-01 10:00:00'],
            [5, '2026-08-01 10:00:00', null, 'processing', '2026-08-01 10:00:01'],
            [6, '2026-08-01 10:00:00', null, 'new', '2026-08-01 10:00:00'],
        ];
        foreach ($fixture as [$orderId, $sentAt, $expiresAt, $state, $updatedAt]) {
            $insertPp->execute([$orderId, $sentAt, $expiresAt]);
            $insertSo->execute([$orderId, $state, $updatedAt]);
        }

        $sql = sprintf(
            'SELECT %s AS reminded_count, %s AS paid_count, %s AS still_unpaid_count, %s AS expired_count'
            . ' FROM pp JOIN so ON so.entity_id = pp.order_id',
            (string)$captured['reminded_count'],
            (string)$captured['paid_count'],
            (string)$captured['still_unpaid_count'],
            (string)$captured['expired_count']
        );
        $result = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(6, (int)$result['reminded_count']);
        $this->assertSame(
            2,
            (int)$result['paid_count'],
            'the same-second payment and the one landing a second later must both count as paid'
        );
        $this->assertSame(
            2,
            (int)$result['still_unpaid_count'],
            'an order in state "new" must land in still-unpaid, not be miscounted as paid'
        );
        $this->assertSame(2, (int)$result['expired_count']);
        $this->assertSame(
            (int)$result['reminded_count'],
            (int)$result['paid_count'] + (int)$result['still_unpaid_count'] + (int)$result['expired_count']
        );
    }

    public function testRestrictsToRemindersSentSinceTheGivenMoment(): void
    {
        $this->select->method('columns')->willReturnSelf();
        $this->select->expects($this->once())
            ->method('where')
            ->with('pp.sent_at >= ?', '2026-08-01 00:00:00')
            ->willReturnSelf();
        $this->connection->method('fetchRow')->willReturn(false);

        $this->reader()->read('2026-08-01 00:00:00');
    }

    public function testReadsTheWholeLogWhenNoStartIsGiven(): void
    {
        $this->select->method('columns')->willReturnSelf();
        $this->select->expects($this->never())->method('where');
        $this->connection->method('fetchRow')->willReturn(false);

        $this->reader()->read();
    }

    private function reader(): ReminderEfficacyReader
    {
        $resource = $this->createMock(AbstractDb::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTable')->willReturnCallback(static fn (string $t): string => $t);

        $collection = $this->createMock(Collection::class);
        $collection->method('getResource')->willReturn($resource);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $efficacyFactory = $this->createMock(ReminderEfficacyFactory::class);
        $efficacyFactory->method('create')->willReturnCallback(
            static fn (array $data = []): ReminderEfficacy => new ReminderEfficacy(...$data)
        );

        return new ReminderEfficacyReader($collectionFactory, $efficacyFactory);
    }
}
