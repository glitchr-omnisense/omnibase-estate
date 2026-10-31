<?php

namespace Base\Estate\Command;

use Base\Estate\Service\OnlinePayments;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The online payments started and not seen paid, read again from the provider - no webhook needed: cron, every 15 minutes. */
#[AsCommand(name: 'estate:payment:check', description: 'Record the rents paid online that neither the tenant\'s return nor a webhook recorded')]
final class PaymentCheckCommand extends Command
{
    public function __construct(private readonly OnlinePayments $online)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Give up a payment started longer ago (a SEPA debit takes up to two weeks)', '15');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $recorded = $this->online->catchUp((int) $input->getOption('days'));
        (new SymfonyStyle($input, $output))->success(sprintf('%d rent(s) paid online recorded.', $recorded));

        return Command::SUCCESS;
    }
}
