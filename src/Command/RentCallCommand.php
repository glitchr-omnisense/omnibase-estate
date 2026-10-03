<?php

namespace Base\Estate\Command;

use Base\Estate\Service\RentCalls;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The month's rents called (and their notices sent): cron, on the 25th for the next month. Run twice, it calls nothing twice. */
#[AsCommand(name: 'estate:rent:call', description: 'Call the rents of a month: one notice per running lease, sent to its tenants')]
final class RentCallCommand extends Command
{
    public function __construct(private readonly RentCalls $rents)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'The month (YYYY-MM)', (new \DateTimeImmutable('first day of next month'))->format('Y-m'))
            ->addOption('no-mail', null, InputOption::VALUE_NONE, 'Call without sending the notices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $month = new \DateTimeImmutable($input->getOption('month').'-01');
        $made = $this->rents->call($month, null, !$input->getOption('no-mail'));
        $io->success(sprintf('%s: %d rent(s) called.', $month->format('Y-m'), \count($made)));

        return Command::SUCCESS;
    }
}
