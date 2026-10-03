<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\Application;
use Base\Estate\Enum\ApplicationStatus;
use Base\Estate\Enum\Guarantee;
use Base\Estate\Enum\Occupation;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\EmailField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/**
 * The applications to rent the free homes, the newest first: who, their income against the
 * rent, their guarantee, their DossierFacile file - and the steps: shortlist, invite to
 * visit, decline, accept (the lease follows).
 */
class ApplicationCrudController extends AbstractEstateCrudController
{
    public const STATUSES = ['new' => 'Nouvelle', 'shortlisted' => 'Retenue', 'visit' => 'Visite proposée', 'accepted' => 'Acceptée', 'declined' => 'Refusée'];
    public const OCCUPATIONS = ['permanent' => 'CDI', 'fixed_term' => 'CDD / intérim', 'civil_servant' => 'Fonctionnaire', 'self_employed' => 'Indépendant', 'student' => 'Étudiant', 'retired' => 'Retraité', 'job_seeker' => 'En recherche d\'emploi', 'other' => 'Autre'];
    public const GUARANTEES = ['visale' => 'Visale', 'person' => 'Garant personne', 'company' => 'Organisme / banque', 'none' => 'Aucune'];

    public static function getEntityFqcn(): string { return Application::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-user-plus'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $step = fn (string $do, string $label, string $icon, callable $when) => Action::new('estateApplication'.ucfirst($do), $label, $icon)
            ->linkToRoute('estate_admin_application', fn (Application $a) => ['id' => $a->getId(), 'do' => $do] + ('shortlist' === $do ? ['_token' => $this->actionToken('estate_application_shortlist', $a->getId())] : []))
            ->displayIf($when);
        $actions = parent::configureActions($actions);
        foreach ([
            $step('shortlist', 'Retenir', 'fa-solid fa-star', fn (Application $a) => ApplicationStatus::NEW === $a->getStatus()),
            $step('visit', 'Proposer une visite', 'fa-solid fa-calendar-check', fn (Application $a) => $a->getStatus()->isOpen()),
            $step('accept', 'Accepter', 'fa-solid fa-file-signature', fn (Application $a) => $a->getStatus()->isOpen()),
            $step('decline', 'Refuser', 'fa-solid fa-xmark', fn (Application $a) => $a->getStatus()->isOpen()),
        ] as $action) {
            $actions->add(Action::DETAIL, $action); // the list keeps to the facts; the steps are on the application's page
        }

        return $actions->disable(Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Candidat')->onlyOnIndex();
        yield AssociationField::new('unit', 'Bien')->setFormType(EntityType::class)->setFormTypeOptions(['class' => \Base\Estate\Entity\Unit::class])->setColumns(4);
        yield TextField::new('incomeRatio', 'Revenus / loyer')->onlyOnIndex()->formatValue(fn ($v) => null === $v ? '—' : number_format((float) $v, 1, ',', '').' ×');
        yield $this->enum('status', 'État', ApplicationStatus::class, fn (ApplicationStatus $s) => self::STATUSES[$s->value])->setColumns(4);
        yield DateTimeField::new('createdAt', 'Reçue le')->hideOnForm();
        yield TextField::new('firstName', 'Prénom')->setColumns(4)->hideOnIndex();
        yield TextField::new('lastName', 'Nom')->setColumns(4)->hideOnIndex();
        yield EmailField::new('email', 'E-mail')->setColumns(4)->hideOnIndex();
        yield TextField::new('phone', 'Téléphone')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('occupants', 'Occupants')->setColumns(2)->hideOnIndex();
        yield DateField::new('moveInOn', 'Entrée souhaitée')->setColumns(3)->hideOnIndex();
        yield $this->enum('occupation', 'Situation', Occupation::class, fn (Occupation $o) => self::OCCUPATIONS[$o->value])->setColumns(3)->hideOnIndex();
        yield $this->euros('income', 'Revenus nets / mois')->setColumns(3)->hideOnIndex();
        yield $this->enum('guarantee', 'Garantie', Guarantee::class, fn (Guarantee $g) => self::GUARANTEES[$g->value])->setColumns(3)->hideOnIndex();
        yield TextField::new('dossierUrl', 'DossierFacile')->setColumns(6)->hideOnIndex();
        yield DateTimeField::new('visitAt', 'Visite')->setColumns(3)->hideOnIndex();
        yield TextareaField::new('message', 'Son message')->setColumns(12)->hideOnIndex();
        yield TextareaField::new('notes', 'Vos notes (jamais montrées au candidat)')->setColumns(12)->hideOnIndex();
    }
}
