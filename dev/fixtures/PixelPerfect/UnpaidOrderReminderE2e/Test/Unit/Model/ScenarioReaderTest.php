<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderE2e\Test\Unit\Model;

use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminderE2e\Model\ScenarioReader;

class ScenarioReaderTest extends TestCase
{
    /** @var Filesystem|MockObject */
    private $filesystem;
    /** @var ReadInterface|MockObject */
    private $varDirectory;
    /** @var SerializerInterface|MockObject */
    private $serializer;
    private ScenarioReader $reader;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->varDirectory = $this->createMock(ReadInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->filesystem->method('getDirectoryRead')->willReturn($this->varDirectory);
        $this->reader = new ScenarioReader($this->filesystem, $this->serializer);
    }

    public function testReturnsEmptyArrayWhenTheFileIsAbsent(): void
    {
        $this->varDirectory->method('isFile')->willReturn(false);

        $this->assertSame([], $this->reader->read());
    }

    public function testReturnsTheDecodedScenario(): void
    {
        $this->varDirectory->method('isFile')->willReturn(true);
        $this->varDirectory->method('readFile')->willReturn('{"kind":"bank"}');
        $this->serializer->method('unserialize')->willReturn(['kind' => 'bank']);

        $this->assertSame(['kind' => 'bank'], $this->reader->read());
    }

    public function testReturnsEmptyArrayWhenTheFileIsMalformed(): void
    {
        $this->varDirectory->method('isFile')->willReturn(true);
        $this->varDirectory->method('readFile')->willReturn('not json');
        $this->serializer->method('unserialize')
            ->willThrowException(new \InvalidArgumentException('bad'));

        $this->assertSame([], $this->reader->read());
    }

    public function testReturnsEmptyArrayWhenTheDecodedValueIsNotAnArray(): void
    {
        $this->varDirectory->method('isFile')->willReturn(true);
        $this->varDirectory->method('readFile')->willReturn('7');
        $this->serializer->method('unserialize')->willReturn(7);

        $this->assertSame([], $this->reader->read());
    }
}
