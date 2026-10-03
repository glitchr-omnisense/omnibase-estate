<?php

namespace Base\Estate\Command;

use Base\Estate\Entity\Organisation;
use Base\Estate\Service\CompanyRecords;
use Doctrine\ORM\EntityManagerInterface;
use Omnistate\Exception\UnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Every company's identity read again from the registers (Sirene / RNE, VIES), by its SIREN: cron, weekly. */
#[AsCommand(name: 'estate:company:refresh', description: 'Read the companies\' legal name, head office and VAT number from the public registers')]
final class CompanyRefreshCommand extends Command
{
    public function __construct(
        private readonly CompanyRecords $records,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failed = false;
        foreach ($this->entityManager->getRepository(Organisation::class)->findAll() as $organisation) {
            try {
                $found = $this->records->refresh($organisation);
            } catch (UnavailableException $e) {
                $io->warning(sprintf('%s: the register did not answer (%s).', $organisation, $e->getMessage()));
                $failed = true;
                continue;
            }
            $found
                ? $io->writeln(sprintf('<info>%s</info>: %s, %s %s %s - TVA %s', $organisation, $organisation->getLegalName(), $organisation->getAddress(), $organisation->getPostcode(), $organisation->getCity(), $organisation->getVatNumber() ?? 'non assujettie'))
                : $io->writeln(sprintf('<comment>%s</comment>: no SIREN, or unknown to the register.', $organisation));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
