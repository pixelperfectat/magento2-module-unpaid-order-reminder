<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderE2e\Test\Unit\Mail;

use Magento\Framework\App\State;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\UnpaidOrderReminderE2e\Mail\CollectingTransport;

class CollectingTransportTest extends TestCase
{
    /** @var Filesystem|MockObject */
    private $filesystem;
    /** @var WriteInterface|MockObject */
    private $varDirectory;
    /** @var EmailMessageInterface|MockObject */
    private $message;
    /** @var DateTime|MockObject */
    private $dateTime;
    /** @var State|MockObject */
    private $appState;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->varDirectory = $this->createMock(WriteInterface::class);
        $this->message = $this->createMock(EmailMessageInterface::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturn(1700000000);
        $this->filesystem->method('getDirectoryWrite')->willReturn($this->varDirectory);
        $this->appState = $this->createMock(State::class);
        $this->appState->method('getMode')->willReturn(State::MODE_DEVELOPER);
    }

    public function testGetMessageReturnsTheMessageItWasBuiltWith(): void
    {
        $transport = $this->transport();

        $this->assertSame($this->message, $transport->getMessage());
    }

    public function testSendMessageRefusesOutsideDeveloperMode(): void
    {
        $appState = $this->createMock(State::class);
        $appState->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $this->varDirectory->expects($this->never())->method('writeFile');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('The collecting mail transport runs in developer mode only.');

        (new CollectingTransport($this->message, $this->filesystem, $this->dateTime, $appState))->sendMessage();
    }

    public function testSendMessageWritesTheWholeMessageToTheMailDirectory(): void
    {
        $this->message->method('toString')->willReturn("Subject: Reminder\r\n\r\nBody text");
        $this->varDirectory->expects($this->once())->method('create')
            ->with(CollectingTransport::MAIL_PATH);
        $this->varDirectory->expects($this->once())->method('writeFile')
            ->with(
                $this->stringContains(CollectingTransport::MAIL_PATH . '/1700000000-'),
                "Subject: Reminder\r\n\r\nBody text"
            );

        $this->transport()->sendMessage();
    }

    public function testTwoMessagesAreWrittenToTwoDifferentFiles(): void
    {
        $this->message->method('toString')->willReturn('anything');
        $written = [];
        $this->varDirectory->method('writeFile')->willReturnCallback(
            static function (string $path) use (&$written): int {
                $written[] = $path;
                return 1;
            }
        );

        $transport = $this->transport();
        $transport->sendMessage();
        $transport->sendMessage();

        $this->assertCount(2, $written);
        $this->assertNotSame($written[0], $written[1]);
    }

    private function transport(): CollectingTransport
    {
        return new CollectingTransport($this->message, $this->filesystem, $this->dateTime, $this->appState);
    }
}
