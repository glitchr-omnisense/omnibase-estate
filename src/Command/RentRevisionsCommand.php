<?php

namespace Base\Estate\Command;

use Base\Estate\Model\RevisionProposal;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\RentRevisions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The rent revisions due within --days, proposed: each lease's anniversary, its quarter and -
 * with omnistate's statistics - INSEE's value, its credit and the new rent, or why it waits.
 * Nothing is applied: with --mail the management gets the list, each line opening the revision
 * screen where a person confirms it. Cron, weekly.
 */
#[AsCommand(name: 'estate:rent:revisions', description: 'Propose the rent revisions due (IRL read from INSEE when omnistate is installed); never applies one')]
final class RentRevisionsCommand extends Command
{
    private const STATUSES = [
        RevisionProposal::READY => 'ready',
        RevisionProposal::WAITING => 'waits for INSEE',
        RevisionProposal::UNAVAILABLE => 'INSEE unavailable: type the value',
        RevisionProposal::MANUAL => 'type the value',
        RevisionProposal::NO_REFERENCE => 'no reference index',
    ];

    public function __construct(
        private readonly RentRevisions $revisions,
        private readonly EstateMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Revisions due within this many days', '45')
            ->addOption('mail', null, InputOption::VALUE_NONE, 'E-mail each organisation the revisions it has to confirm');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $proposals = $this->revisions->proposals(max(0, (int) $input->getOption('days')));
        if (!$proposals) {
            $io->success('No rent revision due.');

            return Command::SUCCESS;
        }
        $io->table(['Lease', 'Home', 'On', 'Quarter', 'Index', 'Rent', 'New rent', 'State'], array_map(static fn (RevisionProposal $p) => [
            $p->lease->getReference(),
            $p->lease->getUnit()->getName(),
            $p->on->format('Y-m-d'),
            $p->quarter ?? '-',
            $p->value?->value ?? '-',
            number_format($p->lease->getRent() / 100, 2, '.', ''),
            null === $p->rent ? '-' : number_format($p->rent / 100, 2, '.', ''),
            self::STATUSES[$p->status].($p->capped ? ' (capped)' : ''),
        ], $proposals));
        $credits = array_unique(array_filter(array_map(static fn (RevisionProposal $p) => $p->value?->credit ? $p->value->credit.($p->value->licence ? ' - '.$p->value->licence : '') : null, $proposals)));
        foreach ($credits as $credit) {
            $io->writeln('Source: '.$credit);
        }

        if ($input->getOption('mail')) {
            $by = [];
            foreach ($proposals as $proposal) {
                $by[$proposal->lease->getOrganisation()->getId()][] = $proposal;
            }
            foreach ($by as $list) {
                $organisation = $list[0]->lease->getOrganisation();
                $this->mailer->send((string) $organisation->getEmail(), sprintf('Révisions de loyer à confirmer (%d)', \count($list)), 'revisions_due', ['organisation' => $organisation, 'proposals' => $list]);
            }
            $io->writeln(sprintf('%d organisation(s) told.', \count($by)));
        }
        $io->success(sprintf('%d revision(s) proposed, none applied: each is confirmed on its revision screen.', \count($proposals)));

        return Command::SUCCESS;
    }
}
