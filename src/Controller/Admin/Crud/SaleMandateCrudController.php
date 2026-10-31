<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\SaleMandate;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\FeeBasis;
use Base\Estate\Enum\FeePayer;
use Base\Estate\Enum\UnitKind;
use Base\Estate\Service\Transactions;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\IntegerField;
use Base\Field\NumberField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The sale mandates: the seller(s), the agency (whose books carry the
 * fee), the property - one of the homes, or described in place -, the
 * asking price, simple or exclusive, from when until when, the fee and who
 * pays it, who holds the deposit. Numbered in the register of mandates
 * when saved (VT-2026-0001); « Suivi » opens its visits, offers and sale.
 */
class SaleMandateCrudController extends AbstractEstateCrudController
{
    private ?Transactions $transactions = null;
    private ?EntityManagerInterface $saleEntityManager = null;

    #[Required]
    public function setTransactions(Transactions $transactions, EntityManagerInterface $entityManager): void
    {
        $this->transactions = $transactions;
        $this->saleEntityManager = $entityManager;
    }

    public static function getEntityFqcn(): string { return SaleMandate::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-sign-hanging'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['startsOn' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $follow = Action::new('estateSaleMandateFollow', 'Suivi', 'fa-solid fa-route')
            ->linkToRoute('estate_admin_transaction_mandate', fn (SaleMandate $m) => ['id' => $m->getId()]);
        $actions = parent::configureActions($actions)->add(Action::INDEX, $follow)->add(Action::DETAIL, $follow);
        if ($this->transactions?->hasCloud()) {
            $folder = Action::new('estateSaleMandateFolder', 'Dossier cloud', 'fa-solid fa-cloud')
                ->linkToRoute('estate_admin_transaction_folder', fn (SaleMandate $m) => ['id' => $m->getId(), '_token' => $this->actionToken('estate_transaction_folder', $m->getId())]);
            $actions->add(Action::DETAIL, $folder);
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('number', 'N° de mandat')->setRequired(false)->setColumns(3)->setHelp('Vide : numéroté au registre à l\'enregistrement (VT-2026-0001).');
        yield AssociationField::new('sellers', 'Vendeur(s)')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Mandator::class, 'multiple' => true, 'by_reference' => false])->setRequired(false)->setColumns(5)->hideOnIndex()->setHelp('Les propriétaires qui signent le mandat ; leur compte ouvre leur espace vendeur.');
        yield TextField::new('sellersSummary', 'Vendeur(s)')->onlyOnIndex();
        yield AssociationField::new('organisation', 'Agence')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Organisation::class])->setColumns(4)->hideOnIndex();
        yield AssociationField::new('unit', 'Bien géré')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Unit::class, 'required' => false])->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('Un des biens ; sinon, décrivez le bien ci-dessous.');
        yield TextField::new('propertyName', 'Bien')->onlyOnIndex();
        yield TextField::new('address', 'Adresse')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('postcode', 'Code postal')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield TextField::new('city', 'Ville')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield $this->enum('kind', 'Type', UnitKind::class, fn (UnitKind $k) => UnitCrudController::KINDS[$k->value])->setFormTypeOptions(['class' => UnitKind::class, 'choice_label' => fn (UnitKind $k) => UnitCrudController::KINDS[$k->value], 'required' => false, 'placeholder' => '—'])->setRequired(false)->setColumns(3)->hideOnIndex();
        yield NumberField::new('surface', 'Surface (m²)')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield IntegerField::new('rooms', 'Pièces')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield $this->euros('price', 'Prix demandé')->setColumns(3);
        yield BooleanField::new('exclusive', 'Exclusif')->setColumns(3);
        yield DateField::new('startsOn', 'Depuis le')->setColumns(3);
        yield DateField::new('endsOn', 'Jusqu\'au (inclus)')->setColumns(3)->setHelp('Un mandat de vente a un terme (loi Hoguet).');
        yield $this->enum('feeBasis', 'Honoraires', FeeBasis::class, fn (FeeBasis $b) => $b->label())->setColumns(3)->hideOnIndex();
        yield NumberField::new('feePercent', 'Taux (% TTC du prix)')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('5 pour 5 % du prix de vente, TVA comprise.');
        yield $this->euros('feeAmount', 'Ou forfait TTC')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield TextField::new('feeLabel', 'Honoraires')->onlyOnIndex();
        yield $this->enum('feePayer', 'À la charge de', FeePayer::class, fn (FeePayer $p) => $p->label())->setColumns(3)->hideOnIndex();
        yield TextField::new('taxCode', 'Code TVA des honoraires')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('V20 : la TVA est comprise dans les honoraires quand le livre de l\'agence y est assujetti.');
        yield BooleanField::new('depositHeldByAgency', 'L\'agence détient le séquestre')->setColumns(5)->hideOnIndex()->setHelp('Sinon le notaire : le dépôt est noté, pas comptabilisé.');
        yield TextareaField::new('notes', 'Notes')->setRequired(false)->setColumns(12)->hideOnIndex();
        yield TextField::new('folder', 'Dossier cloud')->hideOnForm()->hideOnIndex();
    }


    /** A new mandate opens on the first agency (most have one), an ordinary field to change. */
    public function createEntity(string $entityFqcn): SaleMandate
    {
        $mandate = new SaleMandate();
        if (null !== $organisation = $this->saleEntityManager?->getRepository(Organisation::class)->findOneBy([], ['id' => 'ASC'])) {
            $mandate->setOrganisation($organisation);
        }

        return $mandate;
    }

    /** Numbered in the register of mandates when saved without a number; its folder made. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        $this->transactions?->number($entity);
        parent::persistEntity($entityManager, $entity);
        $this->transactions?->syncFolder($entity);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        parent::updateEntity($entityManager, $entity);
        $this->transactions?->syncFolder($entity);
    }
}
