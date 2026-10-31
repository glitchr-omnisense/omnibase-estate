<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Crud;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Service\Ownerships;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\EmailField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The co-owners' shares: who (a person, one of the associates, or the
 * managing company itself), how much (an exact fraction: 1 / 3), from when
 * and until when, their IBAN. Saved, the ownership's shares are checked:
 * on every date they change they add up to one, else the screen says on
 * which date and by how much - an invoice is not divided before.
 */
class OwnershipShareCrudController extends AbstractEstateCrudController
{
    private ?Ownerships $ownerships = null;
    private ?EntityManagerInterface $ownershipEntityManager = null;

    #[Required]
    public function setOwnerships(Ownerships $ownerships, EntityManagerInterface $entityManager): void
    {
        $this->ownerships = $ownerships;
        $this->ownershipEntityManager = $entityManager;
    }

    public static function getEntityFqcn(): string { return OwnershipShare::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-chart-pie'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['ownership' => 'ASC', 'startsOn' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('ownership', 'Indivision')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Ownership::class])->setColumns(4);
        yield TextField::new('name', 'Indivisaire')->setRequired(false)->setColumns(4)->setHelp('Vide pour la société gestionnaire : son nom.');
        yield EmailField::new('email', 'E-mail')->setRequired(false)->setColumns(4)->setHelp('Appels de fonds et relevés partent à cette adresse.');
        yield BooleanField::new('company', 'La société elle-même')->setColumns(3)->hideOnIndex()->setHelp('Sa quote-part reste dans ses propres comptes.');
        yield AssociationField::new('associate', 'Associé·e')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Associate::class, 'required' => false])->setRequired(false)->setColumns(3)->hideOnIndex();
        yield AssociationField::new('user', 'Compte')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('Son compte ouvre son espace indivisaire et le dossier cloud de l\'indivision.');
        yield TextField::new('iban', 'IBAN')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield IntegerField::new('numerator', 'Quote-part : numérateur')->setColumns(3)->onlyOnForms();
        yield IntegerField::new('denominator', 'dénominateur')->setColumns(3)->onlyOnForms()->setHelp('1 / 3 reste 1 / 3 : jamais arrondi.');
        yield TextField::new('fractionLabel', 'Quote-part')->hideOnForm();
        yield DateField::new('startsOn', 'Depuis le')->setColumns(3);
        yield DateField::new('endsOn', 'Jusqu\'au (exclu)')->setRequired(false)->setColumns(3)->setHelp('Le jour où elle ne vaut plus : cession, succession.');
    }

    public function createEntity(string $entityFqcn): OwnershipShare
    {
        $share = new OwnershipShare();
        if (null !== $ownership = $this->ownershipEntityManager?->getRepository(Ownership::class)->findOneBy([], ['id' => 'ASC'])) {
            $share->setOwnership($ownership);
        }

        return $share;
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        $this->complete($entity);
        parent::persistEntity($entityManager, $entity);
        $this->checked($entity);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        $this->complete($entity);
        parent::updateEntity($entityManager, $entity);
        $this->checked($entity);
    }

    /** The company's share named after it, an associate's after them. */
    private function complete(OwnershipShare $share): void
    {
        if ('' === $share->getName()) {
            $share->setName($share->isCompany() ? $share->getOwnership()->getOrganisation()->getName() : ($share->getAssociate()?->getName() ?? $share->getEmail() ?? ''));
        }
        $share->setAssociate($share->getAssociate());
        $share->getOwnership()->addShare($share);
    }

    /** The ownership's shares checked once saved, its folder shared with a new account. */
    private function checked(OwnershipShare $share): void
    {
        $ownership = $share->getOwnership();
        $problems = $ownership->problems();
        if ([] === $problems) {
            $this->addFlash('success', sprintf('%s : les quotes-parts font bien 1.', $ownership->getName()));
        } else {
            $this->addFlash('warning', sprintf('%s : les quotes-parts ne font pas 1 — %s. Complétez-les avant de répartir une facture.', $ownership->getName(),
                implode(' ; ', array_map(fn (array $p) => sprintf('%s au %s', $p['sum'], $p['on']->format('d/m/Y')), $problems))));
        }
        $this->ownerships?->syncFolder($ownership);
    }
}
