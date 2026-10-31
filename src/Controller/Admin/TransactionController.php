<?php

namespace Base\Estate\Controller\Admin;

use Base\Estate\Entity\Buyer;
use Base\Estate\Entity\Offer;
use Base\Estate\Entity\Sale;
use Base\Estate\Entity\SaleMandate;
use Base\Estate\Enum\SaleStep;
use Base\Estate\Service\Transactions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The sales' back-office pages that are not a plain form: a mandate's page
 * (its visits, its offers - accepted, countered, refused, withdrawn -, its
 * sale's steps, moved to the next one with its date and document, or
 * cancelled), the sales going on, a buyer matched against the mandates,
 * a mandate's cloud folder made again. Only while the site runs
 * `transaction`.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estate/transaction')]
class TransactionController extends AbstractController
{
    public function __construct(private readonly Transactions $transactions)
    {
    }

    /** A mandate's page: the property and its terms, the visits, the offers, the sale and its steps. */
    #[Route('/mandate/{id}', name: 'estate_admin_transaction_mandate', requirements: ['id' => '\d+'])]
    public function mandate(SaleMandate $mandate): Response
    {
        $this->assertActive();
        $sale = $this->transactions->saleOf($mandate);

        return $this->render('@Estate/admin/transaction_mandate.html.twig', [
            'mandate' => $mandate,
            'visits' => $this->transactions->visits($mandate),
            'offers' => $this->transactions->offers($mandate),
            'sale' => $sale,
            'cancelled' => array_values(array_filter($this->transactions->salesOf($mandate), fn (Sale $s) => SaleStep::CANCELLED === $s->getStep())),
            'fee' => $this->transactions->fee($mandate, $sale?->getPrice() ?? $mandate->getPrice()),
            'steps' => SaleStep::PATH,
            'books' => $this->transactions->hasBooks(),
            'cloud' => $this->transactions->hasCloud(),
            'today' => new \DateTimeImmutable('today'),
        ]);
    }

    /** An offer answered: accepted (the sale begins), countered at an amount, refused, or withdrawn by the buyer. */
    #[Route('/offer/{id}', name: 'estate_admin_transaction_offer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function offer(Request $request, Offer $offer): RedirectResponse
    {
        $this->assertActive();
        $this->checkToken($request, 'estate_transaction_offer_'.$offer->getId());
        $on = self::date($request->request->get('on')) ?? new \DateTimeImmutable('today');
        try {
            match ((string) $request->request->get('do')) {
                'accept' => $this->transactions->accept($offer, $on),
                'counter' => $this->transactions->counter($offer, self::cents($request->request->get('amount')), $on),
                'refuse' => $this->transactions->refuse($offer, $on),
                'withdraw' => $this->transactions->withdraw($offer, $on),
                default => throw new \InvalidArgumentException('Que faire de cette offre ?'),
            };
            $this->addFlash('success', sprintf('Offre de %s : %s.', $offer->getBuyer()->getName(), mb_strtolower($offer->getStatus()->label())));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('estate_admin_transaction_mandate', ['id' => $offer->getMandate()->getId()]);
    }

    /** The sale moved to its next step: its date, what it brings, a few words, its document. */
    #[Route('/sale/{id}/advance', name: 'estate_admin_transaction_advance', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function advance(Request $request, Sale $sale): RedirectResponse
    {
        $this->assertActive();
        $this->checkToken($request, 'estate_transaction_sale_'.$sale->getId());
        $step = SaleStep::tryFrom((string) $request->request->get('step'));
        try {
            if (null === $step) {
                throw new \InvalidArgumentException('Quelle étape ?');
            }
            $data = array_filter([
                'notary' => $request->request->get('notary') ?: null,
                'deposit' => '' !== (string) $request->request->get('deposit') ? self::cents($request->request->get('deposit')) : null,
                'notifiedOn' => self::date($request->request->get('notifiedOn')),
                'loanDeadline' => self::date($request->request->get('loanDeadline')),
                'deedPlannedOn' => self::date($request->request->get('deedPlannedOn')),
            ], fn ($value) => null !== $value);
            $this->transactions->advance($sale, $step, self::date($request->request->get('on')) ?? new \DateTimeImmutable('today'), $data,
                $request->request->get('note') ?: null, $request->files->get('file'));
            $this->addFlash('success', sprintf('%s : %s le %s.', $sale->getMandate()->getNumber(), $step->label(), $sale->dateOf($step)?->format('d/m/Y')));
            if (SaleStep::DEED === $step && $sale->getFeeTotal() > 0) {
                $this->addFlash('success', sprintf('Honoraires facturés (%s) : %s TTC, à la charge %s.', $sale->getInvoiceNumber(), number_format($sale->getFeeTotal() / 100, 2, ',', ' ').' €', \Base\Estate\Enum\FeePayer::BUYER === $sale->getFeePayer() ? 'de l\'acquéreur' : 'du vendeur'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('estate_admin_transaction_mandate', ['id' => $sale->getMandate()->getId()]);
    }

    /** The sale cancelled before the acte, with its reason; the deposit the agency holds given back (or to the seller). */
    #[Route('/sale/{id}/cancel', name: 'estate_admin_transaction_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        $this->assertActive();
        $this->checkToken($request, 'estate_transaction_sale_'.$sale->getId());
        try {
            $this->transactions->cancel($sale, (string) $request->request->get('reason'), self::date($request->request->get('on')), $request->request->getBoolean('forfeited'));
            $this->addFlash('success', sprintf('Vente %s annulée : le mandat peut recevoir une autre offre.', $sale->getMandate()->getNumber()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('estate_admin_transaction_mandate', ['id' => $sale->getMandate()->getId()]);
    }

    /** The sales going on, their next step and date; the offers waiting; the quarter's fees. */
    #[Route('/sales', name: 'estate_admin_transaction_sales')]
    public function sales(): Response
    {
        $this->assertActive();
        $today = new \DateTimeImmutable('today');

        return $this->render('@Estate/admin/transaction_sales.html.twig', [
            'sales' => $this->transactions->salesInProgress(),
            'offers' => $this->transactions->offersWaiting(),
            'ending' => $this->transactions->ending($today, $today->modify('+1 month')),
            'fees' => $this->transactions->feesOfQuarter($today),
            'transactions' => $this->transactions,
        ]);
    }

    /** A buyer against the mandates: a short list, and why each fits. */
    #[Route('/buyer/{id}/matches', name: 'estate_admin_transaction_matches', requirements: ['id' => '\d+'])]
    public function matches(Buyer $buyer): Response
    {
        $this->assertActive();

        return $this->render('@Estate/admin/transaction_matches.html.twig', ['buyer' => $buyer, 'matches' => $this->transactions->matches($buyer)]);
    }

    /** The mandate's cloud folder made again and shared (omnibase/drive). */
    #[Route('/mandate/{id}/folder', name: 'estate_admin_transaction_folder', requirements: ['id' => '\d+'])]
    public function folder(Request $request, SaleMandate $mandate): RedirectResponse
    {
        $this->assertActive();
        if (!$this->isCsrfTokenValid('estate_transaction_folder_'.$mandate->getId(), (string) $request->query->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $path = $this->transactions->syncFolder($mandate);
        null !== $path
            ? $this->addFlash('success', sprintf('Dossier « %s » partagé avec les vendeurs (et l\'acquéreur retenu), en lecture.', $path))
            : $this->addFlash('warning', 'Pas de cloud sur ce site (omnibase/drive), ou le dossier n\'a pu être créé.');

        return $this->redirectToRoute('estate_admin_transaction_mandate', ['id' => $mandate->getId()]);
    }

    private function assertActive(): void
    {
        if (!$this->transactions->isActive()) {
            throw $this->createNotFoundException('The site does not run the transaction activity.');
        }
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    /** "250 000", "249 500,50" as typed: minor units. */
    private static function cents(mixed $typed): int
    {
        $typed = str_replace([' ', "\u{202F}", "\u{00A0}", '€'], '', (string) $typed);

        return (int) round(100 * (float) str_replace(',', '.', $typed));
    }

    private static function date(mixed $typed): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $typed);

        return false === $date ? null : $date;
    }
}
