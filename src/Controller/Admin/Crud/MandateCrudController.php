<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\StatementFrequency;
use Base\Estate\Service\Management;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\NumberField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The management mandates: an owner, the agency (whose books carry their
 * account), the homes, the fee on the rents collected and its VAT code,
 * from when until when, how often the statement and the payout go, the
 * reserve kept. Numbered in the register of mandates when saved
 * (MG-2026-0001).
 */
class MandateCrudController extends AbstractEstateCrudController
{
    private ?Management $management = null;
    private ?EntityManagerInterface $mandateEntityManager = null;

    #[Required]
    public function setManagement(Management $management, EntityManagerInterface $entityManager): void
    {
        $this->management = $management;
        $this->mandateEntityManager = $entityManager;
    }

    public static function getEntityFqcn(): string { return Mandate::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-file-signature'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['startsOn' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $statements = Action::new('estateMandateStatements', 'Comptes rendus', 'fa-solid fa-file-invoice')
            ->linkToRoute('estate_admin_management_statements', fn (Mandate $m) => ['mandate' => $m->getId()]);
        $payouts = Action::new('estateMandatePayouts', 'Reversements', 'fa-solid fa-money-bill-transfer')
            ->linkToRoute('estate_admin_management_payouts', fn () => []);

        return parent::configureActions($actions)
            ->add(Action::INDEX, $statements)->add(Action::DETAIL, $statements)->add(Action::DETAIL, $payouts);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('number', 'N° de mandat')->setRequired(false)->setColumns(3)->setHelp('Vide : numéroté au registre à l\'enregistrement (MG-2026-0001).');
        yield AssociationField::new('mandator', 'Propriétaire')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Mandator::class])->setColumns(5);
        yield AssociationField::new('organisation', 'Agence')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Organisation::class])->setColumns(4)->hideOnIndex()->setHelp('Ses comptes portent le compte du propriétaire.');
        yield AssociationField::new('units', 'Biens')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Unit::class, 'multiple' => true, 'by_reference' => false])->setRequired(false)->setColumns(12)->hideOnIndex();
        yield TextField::new('unitsSummary', 'Biens')->onlyOnIndex();
        yield NumberField::new('feePercent', 'Honoraires (% HT des encaissements)')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('7 pour 7 % des loyers et charges encaissés, avant TVA.');
        yield TextField::new('feeLabel', 'Honoraires')->onlyOnIndex();
        yield TextField::new('taxCode', 'Code TVA des honoraires')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('V20 : la TVA s\'ajoute quand le livre de l\'agence y est assujetti.');
        yield DateField::new('startsOn', 'Depuis le')->setColumns(3);
        yield DateField::new('endsOn', 'Jusqu\'au (inclus)')->setRequired(false)->setColumns(3);
        yield $this->enum('frequency', 'Compte rendu et reversement', StatementFrequency::class, fn (StatementFrequency $f) => $f->label())->setColumns(4)->hideOnIndex();
        yield $this->euros('reserve', 'Réserve conservée')->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('Gardée sur son compte à chaque reversement, pour les dépenses à venir.');
        yield TextareaField::new('notes', 'Notes')->setRequired(false)->setColumns(12)->hideOnIndex();
        yield TextField::new('statementSentFor', 'Dernier compte rendu')->hideOnForm();
    }

    /** A new mandate opens on the first agency and owner (most have one), ordinary fields to change. */
    public function createEntity(string $entityFqcn): Mandate
    {
        $mandate = new Mandate();
        if (null !== $organisation = $this->mandateEntityManager?->getRepository(Organisation::class)->findOneBy([], ['id' => 'ASC'])) {
            $mandate->setOrganisation($organisation);
        }
        if (null !== $mandator = $this->mandateEntityManager?->getRepository(Mandator::class)->findOneBy([], ['id' => 'ASC'])) {
            $mandate->setMandator($mandator);
        }

        return $mandate;
    }

    /** Numbered in the register of mandates when saved without a number. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        $this->management?->number($entity);
        parent::persistEntity($entityManager, $entity);
    }
}
