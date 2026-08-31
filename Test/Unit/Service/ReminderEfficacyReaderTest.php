<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Service;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
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

        $this->assertStringContainsString('so.updated_at > pp.sent_at', (string)$captured['paid_count']);
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
