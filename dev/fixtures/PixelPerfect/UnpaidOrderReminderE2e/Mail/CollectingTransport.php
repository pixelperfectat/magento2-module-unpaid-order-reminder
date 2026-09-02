<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderE2e\Mail;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Filesystem;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\MessageInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * A mail transport that writes every message to disk and sends nothing.
 *
 * The end-to-end suite asserts against the rendered body, because every template fault this module
 * has carried was invisible to the unit suite and visible in the mail. Writing to disk also means a
 * suite run cannot reach a real recipient, which matters on a production database copy.
 */
class CollectingTransport implements TransportInterface
{
    /**
     * Directory the messages are written to, relative to the var directory.
     */
    public const MAIL_PATH = 'tmp/e2e/mails';

    /**
     * Counts messages inside one process, so two sends never collide on a file name.
     *
     * @var int
     */
    private static int $sequence = 0;

    /**
     * @param MessageInterface $message
     * @param Filesystem $filesystem
     * @param DateTime $dateTime
     * @param State $appState
     */
    public function __construct(
        private readonly MessageInterface $message,
        private readonly Filesystem $filesystem,
        private readonly DateTime $dateTime,
        private readonly State $appState
    ) {
    }

    /**
     * Write the message to the mail directory instead of sending it.
     *
     * @return void
     * @throws MailException
     */
    public function sendMessage(): void
    {
        // This transport replaces the application's own, so a fixture module left enabled by
        // accident would swallow every message the installation sends. Outside developer mode it
        // refuses loudly rather than silently collecting production mail.
        if ($this->appState->getMode() !== State::MODE_DEVELOPER) {
            throw new MailException(__('The collecting mail transport runs in developer mode only.'));
        }

        $content = $this->message instanceof EmailMessageInterface
            ? $this->message->toString()
            : (string)$this->message->getBody();

        try {
            $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $directory->create(self::MAIL_PATH);
            $directory->writeFile(
                sprintf(
                    '%s/%d-%d-%06d.eml',
                    self::MAIL_PATH,
                    $this->dateTime->gmtTimestamp(),
                    getmypid(),
                    ++self::$sequence
                ),
                $content
            );
        } catch (FileSystemException $exception) {
            throw new MailException(__('Could not write the captured mail.'), $exception);
        }
    }

    /**
     * Get the message this transport was built with.
     *
     * @return MessageInterface
     */
    public function getMessage(): MessageInterface
    {
        return $this->message;
    }
}
