<?php

namespace Base\Estate\Command;

use Base\Estate\Service\RentCalls;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Rents past due marked late, and their tenants reminded once (estate.reminder_days after the due date): cron, daily. */
#[AsCommand(name: 'estate:rent:late', description: 'Mark unpaid rents past due as late and remind their tenants')]
final class RentLateCommand extends Command
{
    public function __construct(private readonly RentCalls $rents)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-mail', null, InputOption::VALUE_NONE, 'Mark without reminding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $late = $this->rents->markLate(new \DateTimeImmutable('today'), !$input->getOption('no-mail'));
        (new SymfonyStyle($input, $output))->success(sprintf('%d rent(s) late.', \count($late)));

        return Command::SUCCESS;
    }
}
