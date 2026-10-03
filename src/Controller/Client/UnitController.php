<?php

namespace Base\Estate\Controller\Client;

use Base\Estate\Entity\Unit;
use Base\Estate\Enum\UnitKind;
use Base\Estate\Form\ApplicationType;
use Base\Estate\Form\VisitRequestType;
use Base\Estate\Model\ApplicationRequest;
use Base\Estate\Repository\UnitRepository;
use Base\Estate\Service\Applications;
use Base\Estate\Service\EstateMailer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The public site's homes: the published units, each one's page with a visit request and, free, its application form. */
class UnitController extends AbstractController
{
    #[Route('/biens', name: 'estate_units')]
    public function index(Request $request, UnitRepository $units): Response
    {
        $kind = UnitKind::tryFrom((string) $request->query->get('type'))?->value;
        $available = $request->query->getBoolean('libres');

        return $this->render('@Estate/client/units.html.twig', [
            'units' => $units->findPublished($kind, $available),
            // Every published unit, the others hidden: the host may filter in the page.
            'all' => $units->findPublished(),
            'kind' => $kind,
            'available' => $available,
            'kinds' => UnitKind::cases(),
        ]);
    }

    #[Route('/biens/{slug}', name: 'estate_unit', methods: ['GET', 'POST'])]
    public function show(Request $request, string $slug, UnitRepository $units, EstateMailer $mailer): Response
    {
        $unit = $units->findOneBy(['slug' => $slug, 'published' => true]) ?? throw $this->createNotFoundException();
        $form = $this->createForm(VisitRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            // A filled trap is a robot: thanked all the same, and nothing sent.
            if (!trim((string) ($data['website'] ?? ''))) {
                $mailer->send($unit->getOrganisation()->getEmail(), sprintf('[Visite] %s — %s', $unit->getName(), $data['name']), 'visit_request', ['unit' => $unit, 'visit' => $data], $data['email']);
            }
            $this->addFlash('success', 'visit.sent');

            return $this->redirectToRoute('estate_unit', ['slug' => $slug]);
        }

        return $this->render('@Estate/client/unit.html.twig', [
            'unit' => $unit,
            'form' => $form,
            'others' => array_slice(array_filter($units->findPublished(), fn (Unit $u) => $u !== $unit), 0, 3),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Applying for a free home: the form, then the applicant and the management told. */
    #[Route('/biens/{slug}/candidature', name: 'estate_unit_apply', methods: ['GET', 'POST'])]
    public function apply(Request $request, string $slug, UnitRepository $units, Applications $applications): Response
    {
        $unit = $units->findOneBy(['slug' => $slug, 'published' => true]) ?? throw $this->createNotFoundException();
        if (null === $unit->getAvailableFrom()) {
            $this->addFlash('info', 'application.closed');

            return $this->redirectToRoute('estate_unit', ['slug' => $slug]);
        }
        $data = new ApplicationRequest();
        $data->moveInOn = max($unit->getAvailableFrom(), new \DateTimeImmutable('today'));
        $form = $this->createForm(ApplicationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // A filled trap is a robot: thanked all the same, and nothing kept.
            if (!trim((string) $form->get('website')->getData())) {
                $applications->submit($data->toApplication($unit));
            }
            $this->addFlash('success', 'application.sent');

            return $this->redirectToRoute('estate_unit', ['slug' => $slug]);
        }

        return $this->render('@Estate/client/application.html.twig', ['unit' => $unit, 'form' => $form],
            new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
