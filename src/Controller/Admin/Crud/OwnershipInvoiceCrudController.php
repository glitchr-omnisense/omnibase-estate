<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\SplitMode;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\FileField;
use Base\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The invoices a shared ownership's co-owners share: entered here (the
 * supplier, the amount all taxes included, its VAT code, the expense
 * account, the file), then « Répartir » shows each co-owner's part - by
 * the shares, or set by hand - and posts it: the expense, the supplier's
 * debt, a call for funds to each co-owner, e-mailed.
 */
class OwnershipInvoiceCrudController extends AbstractEstateCrudController
{
    private ?EntityManagerInterface $ownershipEntityManager = null;

    #[Required]
    public function setOwnershipEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->ownershipEntityManager = $entityManager;
    }

    public static function getEntityFqcn(): string { return OwnershipInvoice::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-file-invoice'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['date' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $split = Action::new('estateOwnershipSplit', 'Répartir', 'fa-solid fa-divide')
            ->linkToRoute('estate_admin_ownership_invoice', fn (OwnershipInvoice $i) => ['id' => $i->getId()])
            ->displayIf(fn (OwnershipInvoice $i) => !$i->isPosted());
        $parts = Action::new('estateOwnershipParts', 'Répartition', 'fa-solid fa-chart-pie')
            ->linkToRoute('estate_admin_ownership_invoice', fn (OwnershipInvoice $i) => ['id' => $i->getId()])
            ->displayIf(fn (OwnershipInvoice $i) => $i->isPosted());

        return parent::configureActions($actions)
            ->add(Action::INDEX, $split)->add(Action::DETAIL, $split)->add(Action::INDEX, $parts)->add(Action::DETAIL, $parts);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('ownership', 'Indivision')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Ownership::class])->setColumns(4);
        yield TextField::new('label', 'Objet')->setColumns(5);
        yield DateField::new('date', 'Date')->setColumns(3);
        yield AssociationField::new('contractor', 'Artisan')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Contractor::class, 'required' => false])->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('supplierName', 'Ou fournisseur')->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('Quand ce n\'est pas un des artisans : le Trésor public, un assureur.');
        yield TextField::new('supplier', 'Fournisseur')->onlyOnIndex();
        yield TextField::new('number', 'N° de facture')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield $this->euros('amount', 'Montant TTC')->setColumns(3);
        yield TextField::new('taxCode', 'Code TVA')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('A20, A10… : passé en TVA déductible quand le livre est assujetti, sinon le TTC est la charge.');
        yield TextField::new('expenseAccount', 'Compte de charge')->setColumns(3)->hideOnIndex()->setHelp('615 entretien et réparations, 616 assurance, 63512 taxe foncière…');
        yield AssociationField::new('unit', 'Bien')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Unit::class, 'required' => false])->setRequired(false)->setColumns(3)->hideOnIndex();
        yield FileField::new('file', 'Facture (fichier)')->setRequired(false)->setColumns(6)->hideOnIndex();
        yield $this->enum('splitMode', 'Répartition', SplitMode::class, fn (SplitMode $m) => $m->label())->setColumns(6)->hideOnIndex()->setHelp('Les montants ou pourcentages se saisissent sur « Répartir ».');
        yield DateTimeField::new('postedAt', 'Répartie le')->hideOnForm();
    }

    /** A new invoice opens on the first ownership (most have one), an ordinary field to change. */
    public function createEntity(string $entityFqcn): OwnershipInvoice
    {
        $invoice = new OwnershipInvoice();
        if (null !== $ownership = $this->ownershipEntityManager?->getRepository(Ownership::class)->findOneBy([], ['id' => 'ASC'])) {
            $invoice->setOwnership($ownership);
        }

        return $invoice;
    }
}
