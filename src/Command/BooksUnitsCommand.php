<?php

namespace Base\Estate\Command;

use Base\Estate\Service\Books;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Once, after an upgrade: what estate posted before its entries carried their unit gets it (Books::tagUnits()). Run again, it does nothing. Only with omnibase/ledger. */
#[AsCommand(name: 'estate:books:units', description: 'Give the unit\'s analytic dimension to the entry lines estate posted without it')]
final class BooksUnitsCommand extends Command
{
    public function __construct(private readonly Books $books)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SymfonyStyle($input, $output))->success(sprintf('%d entry lines got their unit.', $this->books->tagUnits()));

        return Command::SUCCESS;
    }
}
