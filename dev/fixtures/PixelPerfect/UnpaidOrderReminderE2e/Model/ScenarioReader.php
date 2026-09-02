<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminderE2e\Model;

use InvalidArgumentException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Reads the scenario the runner wrote, so a test controls what the fake gateway returns.
 *
 * A missing or malformed file is not an error. It reads as "no scenario", and the fake provider
 * then behaves like a gateway that returned nothing.
 */
class ScenarioReader
{
    /**
     * Path of the scenario file, relative to the var directory.
     */
    public const SCENARIO_PATH = 'tmp/e2e/scenario.json';

    /**
     * @param Filesystem $filesystem
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * Read the current scenario.
     *
     * @return array<string, mixed> Empty when there is no usable scenario.
     */
    public function read(): array
    {
        try {
            $directory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
            if (!$directory->isFile(self::SCENARIO_PATH)) {
                return [];
            }
            $raw = $directory->readFile(self::SCENARIO_PATH);
        } catch (FileSystemException) {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
