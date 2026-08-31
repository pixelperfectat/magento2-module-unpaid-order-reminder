<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Console\Command;

use Magento\Framework\Console\Cli;
use PixelPerfect\UnpaidOrderReminder\Api\Data\ReminderRunResultInterface;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderRunnerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the reminder job by hand, or shows what it would do.
 */
class SendRemindersCommand extends Command
{
    private const NAME = 'pixelperfect:unpaidorder:send-reminders';
    private const OPTION_DRY_RUN = 'dry-run';

    /**
     * @param ReminderRunnerInterface $runner
     * @param string|null $name
     */
    public function __construct(
        private readonly ReminderRunnerInterface $runner,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Declares the command name, description and its single option.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Send a reminder for every order still awaiting payment.')
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Decide everything, list the result, and send nothing'
            );

        parent::configure();
    }

    /**
     * Execute the reminder command, sending reminders or showing what would be sent.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);
        $result = $this->runner->run($dryRun);

        if ($result->getSentCount() === 0 && $result->getSkippedCount() === 0 && !$dryRun) {
            $output->writeln('No order qualifies for a reminder.');

            return Cli::RETURN_SUCCESS;
        }

        if ($result->getSentCount() === 0 && $result->getSkippedCount() === 0) {
            $output->writeln('No order qualifies for a reminder.');
        }

        $this->renderRows($output, $result->getSent(), ['Order', 'Method', 'Expires']);
        $this->renderRows($output, $result->getSkipped(), ['Order', 'Method', 'Expires', 'Skipped because']);

        $output->writeln($dryRun
            ? sprintf(
                '%d reminder(s) would be sent, %d skipped.',
                $result->getSentCount(),
                $result->getSkippedCount()
            )
            : sprintf(
                '%d reminder(s) sent, %d skipped.',
                $result->getSentCount(),
                $result->getSkippedCount()
            ));

        return $result->getSkippedCount() === 0 ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE;
    }

    /**
     * Render a table of rows to output if rows is not empty.
     *
     * @param OutputInterface $output
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $headers
     * @return void
     */
    private function renderRows(OutputInterface $output, array $rows, array $headers): void
    {
        if ($rows === []) {
            return;
        }

        $withReason = in_array('Skipped because', $headers, true);

        $table = new Table($output);
        $table->setStyle('compact');
        $table->setHeaders($headers);
        foreach ($rows as $row) {
            $cells = [
                (string)($row['increment_id'] ?? ''),
                (string)($row['payment_method'] ?? ''),
                (string)($row['expires_at'] ?? ''),
            ];
            if ($withReason) {
                $cells[] = sprintf('<comment>%s</comment>', (string)($row['reason'] ?? ''));
            }
            $table->addRow($cells);
        }
        $table->render();
    }
}
