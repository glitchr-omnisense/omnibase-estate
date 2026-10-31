<?php

namespace Base\Estate\Controller\Admin;

use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Enum\SplitMode;
use Base\Estate\Model\Period;
use Base\Estate\Service\Ownerships;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The shared ownerships' back-office pages that are not a plain form: a
 * shared invoice's split - each co-owner's part previewed, by the shares
 * or set by hand, then posted and called -, a call sent or paid, the
 * co-owners' statements for a period (each one's PDF, sent by e-mail),
 * the cloud folder made again. Only while the site runs `ownership`.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estate/ownership')]
class OwnershipController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Ownerships $ownerships,
    ) {
    }

    #[Route('/invoice/{id}', name: 'estate_admin_ownership_invoice', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function invoice(Request $request, OwnershipInvoice $invoice): Response
    {
        $this->assertActive();
        $mode = $invoice->getSplitMode();
        $split = $invoice->getSplit();
        $dueOn = $invoice->getDate() > new \DateTimeImmutable('today') ? $invoice->getDate()->modify('+30 days') : new \DateTimeImmutable('+30 days');
        if ($request->isMethod('POST') && !$invoice->isPosted()) {
            if (!$this->isCsrfTokenValid('estate_ownership_invoice_'.$invoice->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $mode = SplitMode::tryFrom((string) $request->request->get('mode')) ?? SplitMode::SHARES;
            $split = [];
            foreach ((array) $request->request->all('split') as $holder => $value) {
                $value = (float) str_replace([' ', ','], ['', '.'], (string) $value);
                // Amounts are typed in euros, percentages in percent: both kept as hundredths.
                $split[(string) $holder] = (int) round(100 * $value);
            }
            $dueOn = new \DateTimeImmutable($request->request->get('dueOn') ?: '+30 days');
            $invoice->setSplitMode($mode)->setSplit(SplitMode::SHARES === $mode ? [] : $split);
            $this->entityManager->flush();
            if ('post' === $request->request->get('do')) {
                try {
                    $calls = $this->ownerships->post($invoice, $dueOn, $request->request->getBoolean('send'));
                    $this->addFlash('success', sprintf('« %s » répartie : %d appel(s) de fonds%s, passés en comptabilité.', $invoice->getLabel(), \count($calls), $request->request->getBoolean('send') ? ' envoyés par e-mail' : ''));

                    return $this->redirectToRoute('admin_crud_ownership_calls_index');
                } catch (\InvalidArgumentException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->render('@Estate/admin/ownership_invoice.html.twig', [
            'invoice' => $invoice,
            'preview' => $invoice->isPosted() ? null : $this->ownerships->split($invoice, $mode, $split),
            'mode' => $mode,
            'modes' => SplitMode::cases(),
            'split' => $split,
            'dueOn' => $dueOn,
            'books' => $this->ownerships->hasBooks(),
        ]);
    }

    #[Route('/call/{id}/send', name: 'estate_admin_ownership_call_send', requirements: ['id' => '\d+'])]
    public function send(Request $request, OwnershipCall $call): RedirectResponse
    {
        $this->assertActive();
        $this->assertToken($request, 'estate_ownership_call_send', $call->getId());
        $this->ownerships->send($call)
            ? $this->addFlash('success', sprintf('Appel %s envoyé à %s (%s).', $call->getReference(), $call->getShare()->getName(), $call->getShare()->getEmail()))
            : $this->addFlash('warning', sprintf('%s n\'a pas d\'adresse e-mail : rien n\'est parti.', $call->getShare()->getName()));

        return $this->back($request);
    }

    #[Route('/call/{id}/payment', name: 'estate_admin_ownership_call_payment', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function payment(Request $request, OwnershipCall $call): Response
    {
        $this->assertActive();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('estate_ownership_payment_'.$call->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $amount = (int) round(100 * (float) str_replace([' ', ','], ['', '.'], (string) $request->request->get('amount')));
            try {
                $this->ownerships->recordPayment($call, $amount, new \DateTimeImmutable($request->request->get('paidOn') ?: 'today'), $request->request->get('reference') ?: null);
                $this->addFlash('success', sprintf('%s : %s € reçus de %s%s.', $call->getReference(), number_format($amount / 100, 2, ',', ' '), $call->getShare()->getName(), $call->isPaid() ? ', appel soldé' : ''));

                return $this->redirectToRoute('admin_crud_ownership_calls_index');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('@Estate/admin/ownership_payment.html.twig', ['call' => $call]);
    }

    /** Every co-owner's statement of an ownership for a period: the figures, each one's PDF, and « Envoyer ». */
    #[Route('/statements', name: 'estate_admin_ownership_statements')]
    public function statements(Request $request): Response
    {
        $this->assertActive();
        $ownerships = $this->entityManager->getRepository(Ownership::class)->findBy([], ['name' => 'ASC']);
        $ownership = $this->entityManager->find(Ownership::class, $request->query->getInt('ownership')) ?? $ownerships[0] ?? null;
        $today = new \DateTimeImmutable('today');
        $periods = Period::recent($today);
        $period = (string) $request->query->get('period', $periods[3] ?? $today->format('Y'));
        try {
            [$from, $to] = Period::parse($period);
        } catch (\InvalidArgumentException) {
            [$from, $to] = Period::parse($period = $today->format('Y'));
        }

        return $this->render('@Estate/admin/ownership_statements.html.twig', [
            'ownerships' => $ownerships,
            'ownership' => $ownership,
            'statements' => $ownership ? $this->ownerships->statements($ownership, $from, $to) : [],
            'period' => $period,
            'periods' => array_map(fn (string $key) => ['key' => $key, 'label' => Period::label(...Period::parse($key))], $periods),
            'label' => Period::label($from, $to),
        ]);
    }

    /** One co-owner's statement as a PDF (the printable page without dompdf). */
    #[Route('/statement/{id}/{period}', name: 'estate_admin_ownership_statement', requirements: ['id' => '\d+', 'period' => '\d{4}(-(\d{2}|[QT][1-4]))?'])]
    public function statement(OwnershipShare $share, string $period): Response
    {
        $this->assertActive();
        [$from, $to] = Period::parse($period);
        $statement = $this->ownerships->statement($share->getOwnership(), $share->holder(), $from, $to);
        if ($pdf = $this->ownerships->statementPdf($statement)) {
            return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$this->ownerships->statementFilename($statement).'"']);
        }

        return new Response($this->ownerships->statementHtml($statement));
    }

    /** The period's statements e-mailed: one co-owner's ($share), or each one's who has an address. */
    #[Route('/{id}/statements/send', name: 'estate_admin_ownership_statements_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendStatements(Request $request, Ownership $ownership): RedirectResponse
    {
        $this->assertActive();
        if (!$this->isCsrfTokenValid('estate_ownership_statements_'.$ownership->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $period = (string) $request->request->get('period');
        [$from, $to] = Period::parse($period);
        $only = $request->request->getInt('share');
        $sent = [];
        foreach ($this->ownerships->statements($ownership, $from, $to) as $statement) {
            if (OwnershipShare::COMPANY === $statement->holder || ($only && $statement->share?->getId() !== $only)) {
                continue;
            }
            if ($this->ownerships->sendStatement($statement)) {
                $sent[] = $statement->getName();
            }
        }
        $sent
            ? $this->addFlash('success', sprintf('Relevé %s envoyé à %s.', Period::label($from, $to), implode(', ', $sent)))
            : $this->addFlash('warning', 'Aucun relevé envoyé : pas d\'adresse e-mail.');

        return $this->redirectToRoute('estate_admin_ownership_statements', ['ownership' => $ownership->getId(), 'period' => $period]);
    }

    /** The cloud folder made again and shared with every co-owner who has an account (omnibase/drive). */
    #[Route('/{id}/folder', name: 'estate_admin_ownership_folder', requirements: ['id' => '\d+'])]
    public function folder(Request $request, Ownership $ownership): RedirectResponse
    {
        $this->assertActive();
        $this->assertToken($request, 'estate_ownership_folder', $ownership->getId());
        $path = $this->ownerships->syncFolder($ownership);
        null !== $path
            ? $this->addFlash('success', sprintf('Dossier « %s » partagé avec les indivisaires qui ont un compte.', $path))
            : $this->addFlash('warning', 'Pas de cloud sur ce site (omnibase/drive), ou le dossier n\'a pu être créé.');

        return $this->back($request);
    }

    private function assertActive(): void
    {
        if (!$this->ownerships->isActive()) {
            throw $this->createNotFoundException('The site does not run the ownership activity.');
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

    private function assertToken(Request $request, string $action, int $id): void
    {
        if (!$this->isCsrfTokenValid($action.'_'.$id, (string) $request->query->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
