<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Crud;
use Base\Estate\Entity\Document;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\DocumentKind;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\FileField;
use Base\Field\TextField;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/**
 * The homes' and leases' documents: the signed lease, the inventory of fixtures, the
 * diagnostics, the building's rules - shared with the tenants or kept for the management -
 * and what tenants and contractors send (insurance certificates, quotes, invoices, photos).
 */
class DocumentCrudController extends AbstractEstateCrudController
{
    private const KINDS = ['lease' => 'Bail', 'inventory' => 'État des lieux', 'diagnostic' => 'Diagnostic', 'rules' => 'Règlement', 'insurance' => 'Attestation d\'assurance', 'quote' => 'Devis', 'invoice' => 'Facture', 'photo' => 'Photo', 'other' => 'Document'];

    public static function getEntityFqcn(): string { return Document::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-file-lines'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield $this->enum('kind', 'Type', DocumentKind::class, fn (DocumentKind $k) => self::KINDS[$k->value])->setColumns(3);
        yield TextField::new('title', 'Titre')->setColumns(5);
        yield FileField::new('file', 'Fichier')->setColumns(4)->setRequired(false);
        yield AssociationField::new('organisation', 'Société')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Organisation::class])->setColumns(3)->hideOnIndex();
        yield AssociationField::new('unit', 'Bien')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Unit::class, 'required' => false])->setColumns(3)->setHelp('Un document du bien (diagnostics, règlement) : vu par ses locataires successifs.');
        yield AssociationField::new('lease', 'Bail')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Lease::class, 'required' => false])->setColumns(3)->setHelp('Un document du bail (bail signé, état des lieux) : vu par ses locataires.');
        yield AssociationField::new('workOrder', 'Intervention')->setFormType(EntityType::class)->setFormTypeOptions(['class' => WorkOrder::class, 'required' => false])->setColumns(3)->hideOnIndex();
        yield BooleanField::new('tenantVisible', 'Visible des locataires')->setColumns(3);
        yield DateField::new('validUntil', 'Valable jusqu\'au')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield TextField::new('uploadedByName', 'Ajouté par')->hideOnForm();
        yield DateTimeField::new('createdAt', 'Ajouté le')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): Document
    {
        return new Document();
    }
}
