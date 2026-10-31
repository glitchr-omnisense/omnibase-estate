<?php

namespace Base\Estate\Controller\Admin;

use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Entity\Mandator;
use Base\Estate\Model\Period;
use Base\Estate\Repository\MandatePayoutRepository;
use Base\Estate\Service\Management;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The management's back-office pages that are not a plain form: the
 * owners' statements for a period (each one's figures and PDF, sent by
 * e-mail one by one or all at once), the payouts (what each owner is due
 * at a day, issued, the SEPA file of those to transfer, one marked paid),
 * an owner's cloud folder made again. Only while the site runs
 * `management`.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estate/management')]
class ManagementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Management $management,
        private readonly MandatePayoutRepository $payouts,
    ) {
    }

    /** Every owner's statement for a period: the figures, each one's PDF, and « Envoyer ». */
    #[Route('/statements', name: 'estate_admin_management_statements')]
    public function statements(Request $request): Response
    {
        $this->assertActive();
        $today = new \DateTimeImmutable('today');
        $periods = Period::recent($today, 4, 2, 2);
        $period = (string) $request->query->get('period', $periods[1] ?? $today->format('Y-m'));
        try {
            [$from, $to] = Period::parse($period);
        } catch (\InvalidArgumentException) {
            [$from, $to] = Period::parse($period = $today->modify('first day of last month')->format('Y-m'));
        }
        $statements = $this->management->statements($from, $to);
        if ($only = $request->query->getInt('mandate')) {
            $statements = array_values(array_filter($statements, fn ($s) => $s->mandate->getId() === $only));
        }

        return $this->render('@Estate/admin/management_statements.html.twig', [
            'statements' => $statements,
            'period' => $period,
            'periods' => array_map(fn (string $key) => ['key' => $key, 'label' => Period::label(...Period::parse($key))], $periods),
            'label' => Period::label($from, $to),
            'only' => $only,
            'toSend' => $this->management->statementsToSend($today),
        ]);
    }

    /** One owner's statement as a PDF (the printable page without dompdf). */
    #[Route('/statement/{id}/{period}', name: 'estate_admin_management_statement', requirements: ['id' => '\d+', 'period' => '\d{4}(-(\d{2}|[QT][1-4]))?'])]
    public function statement(Mandate $mandate, string $period): Response
    {
        $this->assertActive();
        [$from, $to] = Period::parse($period);
        $statement = $this->management->statement($mandate, $from, $to);
        if ($pdf = $this->management->statementPdf($statement)) {
            return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$this->management->statementFilename($statement).'"']);
        }

        return new Response($this->management->statementHtml($statement));
    }

    /** The period's statements e-mailed: one mandate's, or each owner's who has an address. */
    #[Route('/statements/send', name: 'estate_admin_management_statements_send', methods: ['POST'])]
    public function sendStatements(Request $request): RedirectResponse
    {
        $this->assertActive();
        if (!$this->isCsrfTokenValid('estate_management_statements', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $period = (string) $request->request->get('period');
        [$from, $to] = Period::parse($period);
        $only = $request->request->getInt('mandate');
        $sent = [];
        foreach ($this->management->statements($from, $to) as $statement) {
            if ($only && $statement->mandate->getId() !== $only) {
                continue;
            }
            if ($this->management->sendStatement($statement)) {
                $sent[] = $statement->getName();
            }
        }
        $sent
            ? $this->addFlash('success', sprintf('Compte rendu de %s envoyé à %s.', Period::label($from, $to), implode(', ', $sent)))
            : $this->addFlash('warning', 'Aucun compte rendu envoyé : pas d\'adresse e-mail.');

        return $this->redirectToRoute('estate_admin_management_statements', ['period' => $period] + ($only ? ['mandate' => $only] : []));
    }

    /**
     * The payouts: what each owner is due at a day (their balance less the
     * reserve), issued in one go; those not paid yet, as a SEPA file to give
     * the bank.
     */
    #[Route('/payouts', name: 'estate_admin_management_payouts', methods: ['GET', 'POST'])]
    public function payouts(Request $request): Response
    {
        $this->assertActive();
        $on = new \DateTimeImmutable((string) ($request->request->get('on') ?: $request->query->get('on') ?: 'last day of last month'));
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('estate_management_payouts', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            if ('issue' === $request->request->get('do')) {
                $issued = $this->management->issuePayouts($on);
                $issued
                    ? $this->addFlash('success', sprintf('%d reversement(s) émis au %s, pour %s €.', \count($issued), $on->format('d/m/Y'), number_format(array_sum(array_map(fn (MandatePayout $p) => $p->getAmount(), $issued)) / 100, 2, ',', ' ')))
                    : $this->addFlash('warning', sprintf('Rien à reverser au %s.', $on->format('d/m/Y')));

                return $this->redirectToRoute('estate_admin_management_payouts', ['on' => $on->format('Y-m-d')]);
            }
            if ('sepa' === $request->request->get('do')) {
                $ids = array_map('intval', (array) $request->request->all('payouts'));
                $chosen = array_values(array_filter($this->payouts->findUnpaid(), fn (MandatePayout $p) => [] === $ids || \in_array($p->getId(), $ids, true)));
                $execution = $request->request->get('execution') ? new \DateTimeImmutable((string) $request->request->get('execution')) : null;
                try {
                    $xml = $this->management->sepa($chosen, $execution);

                    return new Response($xml, 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="reversements-'.date('Ymd-His').'.xml"']);
                } catch (\InvalidArgumentException $e) {
                    $this->addFlash('danger', $e->getMessage());

                    return $this->redirectToRoute('estate_admin_management_payouts', ['on' => $on->format('Y-m-d')]);
                }
            }
            if ($request->request->getInt('cancel') && $payout = $this->entityManager->find(MandatePayout::class, $request->request->getInt('cancel'))) {
                try {
                    $this->management->cancelPayout($payout);
                    $this->addFlash('success', 'Reversement annulé : le solde du propriétaire est rétabli.');
                } catch (\InvalidArgumentException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }

                return $this->redirectToRoute('estate_admin_management_payouts', ['on' => $on->format('Y-m-d')]);
            }
        }

        return $this->render('@Estate/admin/management_payouts.html.twig', [
            'on' => $on,
            'due' => $this->management->payoutsDue($on),
            'unpaid' => $this->payouts->findUnpaid(),
            'recent' => array_values(array_filter($this->payouts->forMandate(null, 20), fn (MandatePayout $p) => $p->isPaid())),
            'sepa' => $this->management->hasSepa(),
        ]);
    }

    /** A payout the bank reconciliation did not match, marked paid by hand. */
    #[Route('/payout/{id}/paid', name: 'estate_admin_management_payout_paid', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function paid(Request $request, MandatePayout $payout): Response
    {
        $this->assertActive();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('estate_management_payout_'.$payout->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $this->management->markPaid($payout, new \DateTimeImmutable($request->request->get('paidOn') ?: 'today'), $request->request->get('reference') ?: null);
            $this->addFlash('success', sprintf('%s : %s € virés à %s.', $payout->getReference(), number_format($payout->getAmount() / 100, 2, ',', ' '), $payout->getMandator()->getName()));

            return $this->redirectToRoute('estate_admin_management_payouts');
        }

        return $this->render('@Estate/admin/management_payout_paid.html.twig', ['payout' => $payout]);
    }

    /** The owner's cloud folder made again and shared with them (omnibase/drive). */
    #[Route('/mandator/{id}/folder', name: 'estate_admin_management_folder', requirements: ['id' => '\d+'])]
    public function folder(Request $request, Mandator $mandator): RedirectResponse
    {
        $this->assertActive();
        if (!$this->isCsrfTokenValid('estate_management_folder_'.$mandator->getId(), (string) $request->query->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $path = $this->management->syncFolder($mandator);
        null !== $path
            ? $this->addFlash('success', sprintf('Dossier « %s » partagé avec %s, en lecture.', $path, $mandator->getName()))
            : $this->addFlash('warning', 'Pas de cloud sur ce site (omnibase/drive), ou le dossier n\'a pu être créé.');

        return $this->back($request);
    }

    private function assertActive(): void
    {
        if (!$this->management->isActive()) {
            throw $this->createNotFoundException('The site does not run the management activity.');
        }
    }

    /** Back to the back-office page the button was on, and only to one (see ActionController::back()). */
    private function back(Request $request): RedirectResponse
    {
        $admin = $this->generateUrl('admin');
        $referer = (string) $request->headers->get('referer');
        $path = parse_url($referer, \PHP_URL_PATH);
        $within = parse_url($referer, \PHP_URL_HOST) === $request->getHost() && \is_string($path) && str_starts_with($path, $admin);

        return $this->redirect($within ? $referer : $admin);
    }
}
