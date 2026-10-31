<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\RentCall;
use Base\Estate\Repository\RentCallRepository;
use Twig\Environment;

/**
 * Receipts (quittances): numbered per year in the order rents are paid
 * ("Q-2026-0007"), drawn from @Estate/pdf/receipt.html.twig, as a PDF when
 * dompdf is installed.
 */
class Receipts
{
    public function __construct(
        private readonly RentCallRepository $calls,
        private readonly Environment $twig,
    ) {
    }

    public function number(RentCall $call): string
    {
        if ($call->getReceiptNumber()) {
            return $call->getReceiptNumber();
        }
        $prefix = 'Q-'.$call->getPeriod()->format('Y').'-';
        $last = $this->calls->lastReceiptNumber($prefix);
        $number = $prefix.str_pad((string) (($last ? (int) substr($last, \strlen($prefix)) : 0) + 1), 4, '0', \STR_PAD_LEFT);
        $call->setReceiptNumber($number);

        return $number;
    }

    public function html(RentCall $call, string $kind = 'receipt'): string
    {
        return $this->twig->render('@Estate/pdf/'.$kind.'.html.twig', ['call' => $call, 'lease' => $call->getLease(), 'organisation' => $call->getLease()->getOrganisation()]);
    }

    /** The PDF's bytes, or null without dompdf. */
    public function pdf(RentCall $call, string $kind = 'receipt'): ?string
    {
        return $this->render($this->html($call, $kind));
    }

    /**
     * The certificate a tenant hands a bank, the CAF or a future landlord: they live in the
     * home since the lease's start, at this rent, and owe nothing today.
     */
    public function attestationHtml(Lease $lease, \DateTimeImmutable $on): string
    {
        return $this->twig->render('@Estate/pdf/attestation.html.twig', ['lease' => $lease, 'organisation' => $lease->getOrganisation(), 'on' => $on]);
    }

    public function attestationPdf(Lease $lease, \DateTimeImmutable $on): ?string
    {
        return $this->render($this->attestationHtml($lease, $on));
    }

    /** Any of the bundle's printable pages as a PDF (a co-owner's statement), or null without dompdf. */
    public function htmlToPdf(string $html): ?string
    {
        return $this->render($html);
    }

    private function render(string $html): ?string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return null;
        }
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'Helvetica']);
        // Helvetica (PDF's own font) has no narrow no-break space - the thousands separator of French
        // amounts, "1 500,00 €" - nor a minus sign: their nearest it has, rather than a "?".
        $dompdf->loadHtml(str_replace(["\u{202F}", "\u{2212}"], ["\u{00A0}", '-'], $html));
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
