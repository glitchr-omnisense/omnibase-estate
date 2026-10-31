<?php

namespace Base\Estate\Command;

use Base\Estate\Service\Management;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * After a management mandate is entered for homes already let: the rents
 * collected for them since the mandate's start are the owner's, and the
 * agency's fee is charged on each (Management::catchUp()). Run again, it
 * charges nothing twice. Only while the site runs the `management`
 * activity.
 */
#[AsCommand(name: 'estate:management:income', description: 'Credit the owners with the rents collected for their homes before their mandate was entered, and charge the fees')]
final class ManagementIncomeCommand extends Command
{
    public function __construct(private readonly Management $management)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->management->isActive()) {
            $io->note('The site does not run the management activity (estate.activities): nothing to do.');

            return Command::SUCCESS;
        }
        $io->success(sprintf('%d rent payment(s) of the managed homes looked at; the owners are credited and the fees charged.', $this->management->catchUp()));

        return Command::SUCCESS;
    }
}
