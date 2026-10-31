<?php

namespace Base\Estate\Controller\Client;

use Base\Database\Attribute\Uploader;
use Base\Entity\User;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Model\Period;
use Base\Estate\Security\Voter\EstateVoter;
use Base\Estate\Service\Ownerships;
use Base\Estate\Service\Spaces;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The co-owner's space: their shared ownerships - their share, where their
 * account stands, their calls for funds and what is left to pay, the
 * invoices shared (their files), their statements by month, quarter and
 * year as PDFs, and the ownership's cloud folder. Read-only. Only while
 * the site runs the `ownership` activity.
 */
#[IsGranted('ROLE_USER')]
#[Route('/indivision')]
class CoOwnerController extends AbstractController
{
    public function __construct(
        private readonly Spaces $spaces,
        private readonly Ownerships $ownerships,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'estate_coowner')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $ownerships = $this->spaces->ownerships($user);
        if (!$ownerships) {
            throw $this->createAccessDeniedException('Not a co-owner.');
        }
        $ownership = $ownerships[0];
        foreach ($ownerships as $candidate) {
            if ($candidate->getId() === $request->query->getInt('indivision')) {
                $ownership = $candidate;
            }
        }
        $holders = $this->spaces->holdersIn($user, $ownership);
        $holder = $holders[0];
        $today = new \DateTimeImmutable('today');
        $year = Period::parse($today->format('Y'));
        $statement = $this->ownerships->statement($ownership, $holder, $year[0], $today);
        $calls = array_values(array_filter(
            $this->entityManager->getRepository(OwnershipCall::class)->findBy(['ownership' => $ownership], ['issuedOn' => 'DESC', 'id' => 'DESC']),
            fn (OwnershipCall $call) => \in_array($call->getHolder(), $holders, true),
        ));

        return $this->render('@Estate/client/coowner.html.twig', [
            'ownerships' => $ownerships,
            'ownership' => $ownership,
            'share' => $ownership->shareOf($holder, $today),
            'holder' => $holder,
            'statement' => $statement,
            'calls' => $calls,
            'invoices' => $this->entityManager->getRepository(OwnershipInvoice::class)->findBy(['ownership' => $ownership], ['date' => 'DESC']),
            'periods' => array_map(fn (string $key) => ['key' => $key] + array_combine(['from', 'to'], Period::parse($key)), Period::recent($today)),
            'folder' => $this->ownerships->hasCloud() ? $ownership->getFolder() : null,
        ]);
    }

    /** Their statement for a period: a PDF, or the printable page without dompdf. */
    #[Route('/{id}/releve/{period}', name: 'estate_coowner_statement', requirements: ['id' => '\d+', 'period' => '\d{4}(-(\d{2}|[QT][1-4]))?'])]
    public function statement(Ownership $ownership, string $period): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::OWNERSHIP_VIEW, $ownership);
        $holders = $this->spaces->holdersIn($this->getUser(), $ownership);
        if (!$holders) {
            throw $this->createAccessDeniedException('Not a co-owner of this ownership.');
        }
        [$from, $to] = Period::parse($period);
        $statement = $this->ownerships->statement($ownership, $holders[0], $from, $to);
        if ($pdf = $this->ownerships->statementPdf($statement)) {
            return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$this->ownerships->statementFilename($statement).'"']);
        }

        return new Response($this->ownerships->statementHtml($statement));
    }

    /** A shared invoice's file, to the ownership's co-owners and its management. */
    #[Route('/facture/{id}', name: 'estate_ownership_invoice_file', requirements: ['id' => '\d+'])]
    public function invoice(OwnershipInvoice $invoice): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(EstateVoter::OWNERSHIP_VIEW, $invoice);
        $file = Uploader::get($invoice, 'file');
        if (!$file instanceof File || !$file->isFile()) {
            throw $this->createNotFoundException('The file is missing.');
        }
        $extension = $file->guessExtension() ?: $file->getExtension();
        $name = trim(preg_replace('/[^\pL\pN _.-]+/u', '', $invoice->getLabel())) ?: 'facture';

        return (new BinaryFileResponse($file, 200, ['Content-Type' => $file->getMimeType() ?? 'application/octet-stream']))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name.($extension ? '.'.$extension : ''), 'facture'.($extension ? '.'.$extension : ''));
    }
}
