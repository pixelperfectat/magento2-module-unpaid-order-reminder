<?php
declare(strict_types=1);

namespace PixelPerfect\UnpaidOrderReminder\Console\Command;

use Magento\Framework\Console\Cli;
use PixelPerfect\UnpaidOrderReminder\Api\Service\ReminderEfficacyReaderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports how the reminders performed.
 */
class ReminderStatsCommand extends Command
{
    private const NAME = 'pixelperfect:unpaidorder:reminder-stats';
    private const OPTION_SINCE = 'since';

    /**
     * @param ReminderEfficacyReaderInterface $reader
     * @param string|null $name
     */
    public function __construct(
        private readonly ReminderEfficacyReaderInterface $reader,
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
            ->setDescription('Report how many reminded orders were subsequently paid.')
            ->addOption(
                self::OPTION_SINCE,
                null,
                InputOption::VALUE_REQUIRED,
                'Only reminders sent at or after this UTC moment, as Y-m-d H:i:s'
            );

        parent::configure();
    }

    /**
     * Reads the efficacy figures and prints them as a table plus the overall conversion rate.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = $input->getOption(self::OPTION_SINCE);
        $efficacy = $this->reader->read($since === null ? null : (string)$since);

        if ($efficacy->getRemindedCount() === 0) {
            $output->writeln('No reminder has been sent yet.');

            return Cli::RETURN_SUCCESS;
        }

        $table = new Table($output);
        $table->setStyle('compact');
        $table->setHeaders(['Group', 'Orders', 'Value']);
        $table->setRows([
            ['Reminded', $efficacy->getRemindedCount(), number_format($efficacy->getRemindedValue(), 2)],
            ['Paid after the reminder', $efficacy->getPaidCount(), number_format($efficacy->getPaidValue(), 2)],
            ['Still unpaid', $efficacy->getStillUnpaidCount(), number_format($efficacy->getStillUnpaidValue(), 2)],
            ['Expired unpaid', $efficacy->getExpiredCount(), number_format($efficacy->getExpiredValue(), 2)],
        ]);
        $table->render();

        $output->writeln(sprintf(
            '<info>%s%%</info> of reminded orders were paid afterwards.',
            number_format(($efficacy->getPaidCount() / $efficacy->getRemindedCount()) * 100, 1)
        ));

        return Cli::RETURN_SUCCESS;
    }
}
