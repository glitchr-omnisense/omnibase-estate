<?php

namespace Base\Estate\Command;

use Base\Estate\Service\Applications;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Applications decided (accepted or declined) more than three months ago, deleted - the CNIL's guidance for applicants' files: cron, daily. */
#[AsCommand(name: 'estate:applications:purge', description: 'Delete applications decided more than three months ago')]
final class ApplicationPurgeCommand extends Command
{
    public function __construct(private readonly Applications $applications)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('months', null, InputOption::VALUE_REQUIRED, 'How long a decided application is kept', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->applications->purge(max(1, (int) $input->getOption('months')));
        (new SymfonyStyle($input, $output))->success(sprintf('%d application(s) deleted.', $count));

        return Command::SUCCESS;
    }
}
