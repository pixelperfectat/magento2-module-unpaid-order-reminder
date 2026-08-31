<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Test\Unit\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderLogInterface;
use PixelPerfect\UnpaidOrderReminder\Model\ReminderLog;
use PixelPerfect\UnpaidOrderReminder\Model\ReminderLogFactory;
use PixelPerfect\UnpaidOrderReminder\Model\ReminderLogRepository;
use PixelPerfect\UnpaidOrderReminder\Model\ResourceModel\ReminderLog as ReminderLogResource;

class ReminderLogRepositoryTest extends TestCase
{
    /** @var ReminderLogResource|MockObject */
    private $resource;
    /** @var ReminderLogFactory|MockObject */
    private $factory;
    private ReminderLogRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ReminderLogResource::class);
        $this->factory = $this->createMock(ReminderLogFactory::class);
        $this->repository = new ReminderLogRepository($this->resource, $this->factory);
    }

    public function testSavesAndReturnsTheLog(): void
    {
        $log = $this->createMock(ReminderLog::class);
        $this->resource->expects($this->once())->method('save')->with($log);

        $this->assertSame($log, $this->repository->save($log));
    }

    /**
     * The unique constraint on order_id is the last line of defence against a double send. A
     * violation must surface, not be swallowed.
     */
    public function testTurnsAResourceFailureIntoACouldNotSaveException(): void
    {
        $log = $this->createMock(ReminderLog::class);
        $this->resource->method('save')->willThrowException(new \Exception('duplicate entry'));

        $this->expectException(CouldNotSaveException::class);

        $this->repository->save($log);
    }

    public function testLoadsALogByItsOrderId(): void
    {
        $log = $this->createMock(ReminderLog::class);
        $log->method('getId')->willReturn(1);
        $this->factory->method('create')->willReturn($log);
        $this->resource->expects($this->once())
            ->method('load')
            ->with($log, 900, ReminderLogInterface::ORDER_ID);

        $this->assertSame($log, $this->repository->getByOrderId(900));
    }

    public function testReturnsNullWhenNoLogExistsForTheOrder(): void
    {
        $log = $this->createMock(ReminderLog::class);
        $log->method('getId')->willReturn(null);
        $this->factory->method('create')->willReturn($log);

        $this->assertNull($this->repository->getByOrderId(900));
    }

    public function testHasBeenRemindedReflectsWhetherARowExists(): void
    {
        $found = $this->createMock(ReminderLog::class);
        $found->method('getId')->willReturn(3);
        $missing = $this->createMock(ReminderLog::class);
        $missing->method('getId')->willReturn(null);
        $this->factory->method('create')->willReturnOnConsecutiveCalls($found, $missing);

        $this->assertTrue($this->repository->hasBeenReminded(900));
        $this->assertFalse($this->repository->hasBeenReminded(901));
    }
}
