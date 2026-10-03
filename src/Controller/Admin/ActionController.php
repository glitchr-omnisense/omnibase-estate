<?php

namespace Base\Estate\Controller\Admin;

use Base\Entity\User;
use Base\Estate\Entity\Application;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Member;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Notice;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Service\Activities;
use Base\Estate\Service\Applications;
use Base\Estate\Service\CompanyRecords;
use Base\Estate\Service\RentRevisions;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\Invitations;
use Base\Estate\Service\Payments;
use Base\Estate\Service\RentCalls;
use Base\Estate\Service\WorkOrders;
use Doctrine\ORM\EntityManagerInterface;
use Omnistate\Exception\UnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The back office's buttons that are not a plain form: invite someone to
 * their space, call the month's rents, record a payment, give a work order
 * to a contractor, close it, send a notice to the tenants, read a company
 * from the registers.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estate')]
class ActionController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/invite/{kind}/{id}', name: 'estate_admin_invite', requirements: ['kind' => 'member|associate|tenant|contractor', 'id' => '\d+'])]
    public function invite(Request $request, string $kind, int $id, Invitations $invitations): RedirectResponse
    {
        $this->assertToken($request, 'estate_invite_'.$kind, $id);
        $target = $this->entityManager->find(['member' => Member::class, 'associate' => Associate::class, 'tenant' => LeaseParty::class, 'contractor' => Contractor::class][$kind], $id)
            ?? throw $this->createNotFoundException();
        /** @var User $user */
        $user = $this->getUser();
        $invitations->invite($target, $user);
        $this->addFlash('success', sprintf('Invitation envoyée à %s (%s).', $target, $target->getEmail()));

        return $this->back($request);
    }

    #[Route('/rents/call', name: 'estate_admin_rents_call')]
    public function call(Request $request, RentCalls $rents): RedirectResponse
    {
        $this->assertToken($request, 'estate_rents_call', 0);
        $month = new \DateTimeImmutable($request->query->get('month') ?: 'first day of this month');
        $made = $rents->call($month);
        $this->addFlash('success', \count($made)
            ? sprintf('%d loyer(s) de %s appelé(s), avis envoyés aux locataires.', \count($made), $rents->month($month))
            : sprintf('Les loyers de %s étaient déjà appelés.', $rents->month($month)));

        return $this->back($request);
    }

    #[Route('/rent/{id}/payment', name: 'estate_admin_payment', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function payment(Request $request, RentCall $call, Payments $payments): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('estate_payment_'.$call->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $amount = (int) round(100 * (float) str_replace([' ', ','], ['', '.'], (string) $request->request->get('amount')));
            $method = PaymentMethod::tryFrom((string) $request->request->get('method')) ?? PaymentMethod::TRANSFER;
            try {
                $payments->record($call, $amount, $method, new \DateTimeImmutable($request->request->get('paidOn') ?: 'today'), $request->request->get('reference') ?: null);
                $this->addFlash('success', sprintf('%s : %s € encaissés%s.', $call->getReference(), number_format($amount / 100, 2, ',', ' '), $call->isPaid() ? ', quittance envoyée' : ''));

                return $this->redirectToRoute('admin_crud_rent_calls_index');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('@Estate/admin/payment.html.twig', ['call' => $call, 'methods' => PaymentMethod::cases()]);
    }

    #[Route('/work-order/{id}/assign', name: 'estate_admin_assign', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function assign(Request $request, WorkOrder $order, WorkOrders $orders): Response
    {
        $contractors = $this->entityManager->getRepository(Contractor::class)->findBy(['organisation' => $order->getOrganisation()], ['trade' => 'ASC', 'company' => 'ASC']);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('estate_assign_'.$order->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $contractor = $this->entityManager->find(Contractor::class, $request->request->getInt('contractor')) ?? throw $this->createNotFoundException();
            $visitAt = $request->request->get('visitAt') ? new \DateTimeImmutable($request->request->get('visitAt')) : null;
            /** @var User $user */
            $user = $this->getUser();
            $orders->assign($order, $contractor, $visitAt, $user, 'Gestion', $request->request->get('note') ?: null);
            $this->addFlash('success', sprintf('Intervention confiée à %s, prévenu par e-mail.', $contractor->getCompany()));

            return $this->redirectToRoute('admin_crud_work_orders_index');
        }

        return $this->render('@Estate/admin/assign.html.twig', ['order' => $order, 'contractors' => $contractors]);
    }

    #[Route('/work-order/{id}/close', name: 'estate_admin_close', requirements: ['id' => '\d+'])]
    public function close(Request $request, WorkOrder $order, WorkOrders $orders): RedirectResponse
    {
        $this->assertToken($request, 'estate_close', $order->getId());
        /** @var User $user */
        $user = $this->getUser();
        $orders->close($order, $user);
        $this->addFlash('success', sprintf('%s clôturée%s.', $order, $order->getInvoiceAmount() ? ', facture passée en comptabilité' : ''));

        return $this->back($request);
    }

    #[Route('/notice/{id}/send', name: 'estate_admin_notice_send', requirements: ['id' => '\d+'])]
    public function send(Request $request, Notice $notice, EstateMailer $mailer, Activities $activities): RedirectResponse
    {
        $this->assertToken($request, 'estate_notice_send', $notice->getId());
        $emails = [];
        foreach ($this->entityManager->getRepository(\Base\Estate\Entity\Unit::class)->findBy(['organisation' => $notice->getOrganisation()]) as $unit) {
            if ($notice->getBuilding() && $unit->getBuilding() !== $notice->getBuilding()) {
                continue;
            }
            foreach ($unit->getCurrentLease()?->getTenants() ?? [] as $party) {
                $emails[] = $party->getEmail();
            }
        }
        foreach (array_unique($emails) as $email) {
            $mailer->send($email, $notice->getTitle(), 'notice', ['notice' => $notice, 'organisation' => $notice->getOrganisation()], $notice->getOrganisation()->getEmail());
        }
        $notice->setSentAt(new \DateTimeImmutable());
        $activities->log($notice->getOrganisation(), ActivityKind::NOTICE, 'Avis aux locataires : '.$notice->getTitle());
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('« %s » envoyé à %d locataire(s).', $notice->getTitle(), \count(array_unique($emails))));

        return $this->back($request);
    }

    /**
     * An application's next step: shortlisted (a click), invited to visit (a date and a word),
     * declined (a word, optional) or accepted (the lease's start; the other applicants
     * declined, if asked) - the applicant told by e-mail but when shortlisted.
     */
    #[Route('/application/{id}/{do}', name: 'estate_admin_application', requirements: ['id' => '\d+', 'do' => 'shortlist|visit|decline|accept'], methods: ['GET', 'POST'])]
    public function application(Request $request, Application $application, string $do, Applications $applications): Response
    {
        if ('shortlist' === $do) {
            $this->assertToken($request, 'estate_application_shortlist', $application->getId());
            $applications->shortlist($application);
            $this->addFlash('success', $application->getName().' : candidature retenue.');

            return $this->back($request);
        }
        if (!$application->getStatus()->isOpen()) {
            $this->addFlash('warning', 'Cette candidature est déjà '.('accepted' === $application->getStatus()->value ? 'acceptée' : 'refusée').'.');

            return $this->redirectToRoute('admin_crud_applications_index');
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('estate_application_'.$do.'_'.$application->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $note = trim((string) $request->request->get('note')) ?: null;
            switch ($do) {
                case 'visit':
                    $applications->inviteToVisit($application, new \DateTimeImmutable((string) $request->request->get('at')), $note);
                    $this->addFlash('success', sprintf('Visite proposée à %s : l\'e-mail est parti.', $application->getName()));
                    break;
                case 'decline':
                    $applications->decline($application, $note);
                    $this->addFlash('success', sprintf('Candidature de %s refusée : l\'e-mail est parti.', $application->getName()));
                    break;
                case 'accept':
                    $lease = $applications->accept($application, new \DateTimeImmutable((string) $request->request->get('startOn')), $request->request->getBoolean('declineOthers'));
                    $this->addFlash('success', sprintf('Bail %s créé pour %s : vérifiez-le, puis invitez le locataire à son espace.', $lease->getReference(), $application->getName()));

                    return $this->redirectToRoute('admin_crud_leases_edit', ['entityId' => $lease->getId()]);
            }

            return $this->redirectToRoute('admin_crud_applications_index');
        }

        return $this->render('@Estate/admin/application.html.twig', [
            'application' => $application,
            'do' => $do,
            'others' => array_values(array_filter($this->entityManager->getRepository(Application::class)->findBy(['unit' => $application->getUnit()]), fn (Application $a) => $a !== $application && $a->getStatus()->isOpen())),
        ]);
    }

    /**
     * The yearly IRL revision of a lease's rent: the new quarter and its index, the date it
     * applies from (the anniversary); "Calculer" shows the new rent, "Appliquer" saves it and
     * sends the tenants the letter.
     */
    #[Route('/lease/{id}/revise', name: 'estate_admin_revise', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function revise(Request $request, Lease $lease, RentRevisions $revisions): Response
    {
        $on = $revisions->nextOn($lease);
        // A revision asked after the anniversary applies from the day it is asked: never backdated.
        $from = max($on, new \DateTimeImmutable('today'));
        $values = ['quarter' => (string) $request->request->get('quarter', ''), 'index' => (string) $request->request->get('index', ''), 'on' => (string) $request->request->get('on', $from->format('Y-m-d'))];
        $rent = null;
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('estate_revise_'.$lease->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            if (null === $lease->getIndexValue()) {
                $error = 'Renseignez d\'abord l\'indice de référence du bail (trimestre et valeur de l\'IRL à la signature ou à la dernière révision).';
            } elseif (!preg_match('/^T[1-4] \d{4}$/', strtoupper(trim($values['quarter']))) || (float) str_replace(',', '.', $values['index']) <= 0) {
                $error = 'Indiquez le trimestre (« T2 2025 ») et la valeur de l\'IRL publiée par l\'INSEE.';
            } else {
                $rent = $revisions->rentAt($lease, $values['index']);
                if ('apply' === $request->request->get('do')) {
                    /** @var User $user */
                    $user = $this->getUser();
                    $former = $revisions->apply($lease, strtoupper(trim($values['quarter'])), $values['index'], new \DateTimeImmutable($values['on']), $user);
                    $this->addFlash('success', sprintf('Loyer révisé : %s → %s à partir du %s ; les locataires ont reçu le courrier.', number_format($former / 100, 2, ',', ' ').' €', number_format($rent / 100, 2, ',', ' ').' €', (new \DateTimeImmutable($values['on']))->format('d/m/Y')));

                    return $this->redirectToRoute('admin');
                }
            }
        }

        return $this->render('@Estate/admin/revise.html.twig', ['lease' => $lease, 'values' => $values, 'rent' => $rent, 'error' => $error, 'next' => $on]);
    }

    /** The company's identity read again from the registers (Sirene / RNE, VIES), by its SIREN - with glitchr/omnistate. */
    #[Route('/company/{id}/refresh', name: 'estate_admin_company_refresh', requirements: ['id' => '\d+'])]
    public function refresh(Request $request, Organisation $organisation, ?CompanyRecords $records = null): RedirectResponse
    {
        $this->assertToken($request, 'estate_company_refresh', $organisation->getId());
        if (null === $records) {
            throw $this->createNotFoundException('glitchr/omnistate is not installed.');
        }
        try {
            $found = $records->refresh($organisation);
            $found
                ? $this->addFlash('success', sprintf('%s : %s, %s %s %s - %s.', $organisation, $organisation->getLegalName(), $organisation->getAddress(), $organisation->getPostcode(), $organisation->getCity(), $organisation->getVatNumber() ? 'TVA '.$organisation->getVatNumber() : 'non assujettie à la TVA (inconnue de VIES)'))
                : $this->addFlash('warning', sprintf('%s : pas de SIREN valide, ou inconnu du registre.', $organisation));
        } catch (UnavailableException) {
            $this->addFlash('danger', 'Le registre ne répond pas pour l\'instant ; rien n\'a été changé.');
        }

        return $this->back($request);
    }

    /**
     * Back to the back-office page the button was on - and only to one: opened as a
     * website-in-website panel over the site (transparent.js nest), the back office's
     * Referer is the site page under the panel, which would then load inside it.
     */
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
