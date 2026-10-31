<?php

namespace Base\Estate\Command;

use Base\Estate\Service\Ownerships;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * After a shared ownership's shares are entered: the rents its homes
 * received before are shared out among its co-owners in the books
 * (Ownerships::catchUpIncome()). Run again, it posts nothing twice. Only
 * with omnibase/ledger, and while the site runs the `ownership` activity.
 */
#[AsCommand(name: 'estate:ownership:income', description: 'Post the co-owners\' parts of the rents a shared ownership received before its shares were entered')]
final class OwnershipIncomeCommand extends Command
{
    public function __construct(private readonly Ownerships $ownerships)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->ownerships->isActive()) {
            $io->note('The site does not run the ownership activity (estate.activities): nothing to do.');

            return Command::SUCCESS;
        }
        $io->success(sprintf('%d rent payment(s) of the ownerships\' homes looked at; their co-owners\' parts are posted.', $this->ownerships->catchUpIncome()));

        return Command::SUCCESS;
    }
}
