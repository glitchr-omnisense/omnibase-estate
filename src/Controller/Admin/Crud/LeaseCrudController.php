<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Drive\LeaseFolders;
use Base\Estate\Entity\Lease;
use Base\Estate\Enum\LeaseKind;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Enum\RevisionRule;
use Base\Estate\Model\LeaseRules;
use Base\Estate\Service\Books;
use Base\Estate\Signature\LeaseSignatures;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Omnibridge\Persistence\UnitOfWork;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The leases: who, which home, the rent and its index, the insurance - and the yearly rent
 * revision. Saved, a signed lease gives each of its tenants their cloud folder (Locataires/<name>,
 * with omnibase/drive: Drive\LeaseFolders), shared with those who have an account - and, with two
 * occupants or more, their common folder Colocations/<home> and its group (Drive\FlatshareFolders).
 */
class LeaseCrudController extends AbstractEstateCrudController
{
    private ?LeaseFolders $folders = null;

    private ?LeaseSignatures $signatures = null;

    private ?Books $books = null;

    /** The rent's VAT read from the company's book (omnibase/ledger): said on the lease's page. */
    #[Required]
    public function setBooks(?Books $books = null): void
    {
        $this->books = $books;
    }

    #[Required]
    public function setLeaseFolders(?LeaseFolders $folders = null): void
    {
        $this->folders = $folders;
    }

    /** The lease signed electronically, with omnisense/omnisign: its state on the lease's page. */
    #[Required]
    public function setLeaseSignatures(?LeaseSignatures $signatures = null): void
    {
        $this->signatures = $signatures;
    }

    public const STATUSES = ['active' => 'En cours', 'notice' => 'Congé donné', 'ended' => 'Terminé'];
    public const METHODS = ['transfer' => 'Virement', 'card' => 'Carte', 'sepa_debit' => 'Prélèvement SEPA', 'cheque' => 'Chèque', 'cash' => 'Espèces'];

    public static function getEntityFqcn(): string { return Lease::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-file-signature'; }

    public function configureActions(Actions $actions): Actions
    {
        $revise = Action::new('estateRevise', 'Réviser le loyer', 'fa-solid fa-arrow-trend-up')
            ->linkToRoute('estate_admin_revise', fn (Lease $l) => ['id' => $l->getId()])
            ->displayIf(fn (Lease $l) => LeaseStatus::ENDED !== $l->getStatus());

        $actions = parent::configureActions($actions)->add(Action::INDEX, $revise)->add(Action::DETAIL, $revise);
        if (null !== $this->signatures) {
            $sign = Action::new('estateSignature', 'Signature', 'fa-solid fa-signature')
                ->linkToRoute('estate_admin_signature', fn (Lease $l) => ['id' => $l->getId()]);
            $actions->add(Action::DETAIL, $sign);
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('reference', 'Référence')->setColumns(3);
        yield AssociationField::new('unit', 'Bien')->setFormType(\Symfony\Bridge\Doctrine\Form\Type\EntityType::class)->setFormTypeOptions(['class' => \Base\Estate\Entity\Unit::class])->setColumns(5);
        yield TextField::new('tenantNames', 'Locataires')->onlyOnIndex();
        yield $this->enum('status', 'État', LeaseStatus::class, fn (LeaseStatus $s) => self::STATUSES[$s->value])->setColumns(4);
        yield $this->enum('kind', 'Type de bail', LeaseKind::class, fn (LeaseKind $k) => $k->label())->setColumns(4)->hideOnIndex();
        yield DateField::new('startOn', 'Début')->setColumns(3);
        yield DateField::new('endOn', 'Fin')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield DateField::new('noticeOn', 'Congé reçu le')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield DateField::new('insuranceUntil', 'Assurance jusqu\'au')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield $this->euros('rent', 'Loyer HC')->setColumns(3);
        yield $this->euros('charges', 'Charges')->setColumns(3)->hideOnIndex();
        yield $this->euros('deposit', 'Dépôt de garantie')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('dueDay', 'Jour d\'échéance')->setColumns(3)->hideOnIndex();
        yield $this->enum('paymentMethod', 'Paiement', PaymentMethod::class, fn (PaymentMethod $m) => self::METHODS[$m->value])->setColumns(3)->hideOnIndex();
        yield TextField::new('indexName', 'Indice')->setColumns(3)->hideOnIndex()
            ->setFormType(\Symfony\Component\Form\Extension\Core\Type\ChoiceType::class)
            ->setFormTypeOptions(['choices' => ['IRL (habitation)' => 'IRL', 'ILC (loyers commerciaux)' => 'ILC', 'ILAT (activités tertiaires)' => 'ILAT']])
            ->setHelp('IRL pour un logement ; ILC ou ILAT, au choix du bail, pour un bail commercial ou professionnel.');
        yield $this->enum('revisionRule', 'Révision du loyer', RevisionRule::class, fn (RevisionRule $r) => $r->label())->setColumns(5)->hideOnIndex();
        yield BooleanField::new('smallBusiness', 'Locataire PME')->setColumns(4)->hideOnIndex()->setHelp('Petite ou moyenne entreprise (annexe I du règlement (UE) n° 651/2014) : la variation de l\'ILC était plafonnée à 3,5 % du T2 2022 au T1 2024 (loi n° 2022-1158, art. 14).');
        yield TextField::new('indexQuarter', 'Indice de référence (trimestre)')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('« T2 2025 » : celui du bail, ou de la dernière révision.');
        yield TextField::new('indexValue', 'Indice de référence (valeur)')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('La valeur publiée par l\'INSEE pour ce trimestre.');
        yield TextField::new('taxCode', 'Code TVA du loyer')->setRequired(false)->setColumns(3)->hideOnIndex()
            ->setHelp('Si le bailleur a opté pour la TVA sur ce loyer : le code du livre comptable (« V20 » : 20 %). Vide : pas de TVA.')
            ->formatValue(fn ($code, ?Lease $lease) => null === $lease ? '' : $this->vatState($lease));
        yield BooleanField::new('taxCharges', 'TVA sur les charges aussi')->setColumns(3)->hideOnIndex()->setHelp('À décider avec votre expert-comptable.');
        yield DateField::new('revisedOn', 'Dernière révision')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield TextareaField::new('notes', 'Notes')->setRequired(false)->setColumns(12)->hideOnIndex();
        // What the law asks to check on this lease (Model\LeaseRules), only when there is something.
        yield TextField::new('groupId', 'À vérifier')->onlyOnDetail()
            ->formatValue(fn ($v, ?Lease $lease) => null === $lease ? '' : (implode(' ', LeaseRules::warnings($lease)) ?: 'Rien à signaler'));
        if (null !== $this->signatures) {
            // Where its electronic signature stands: sent, signed, refused, expired.
            yield TextField::new('id', 'Signature électronique')->onlyOnDetail()
                ->formatValue(fn ($id, ?Lease $lease) => null === $lease ? '' : $this->signatureState($lease));
        }
    }

    public function persistRecord(UnitOfWork $unitOfWork, object $entity): void
    {
        parent::persistRecord($unitOfWork, $entity);
        $this->syncFolders($entity);
    }

    public function updateRecord(UnitOfWork $unitOfWork, object $entity): void
    {
        parent::updateRecord($unitOfWork, $entity);
        $this->syncFolders($entity);
    }

    /** Whether the rent is called with VAT, and why not when a code is named. */
    private function vatState(Lease $lease): string
    {
        if (null === $lease->getTaxCode()) {
            return 'Pas de TVA';
        }
        $tax = $this->books?->tax($lease);
        if (null === $tax) {
            return sprintf('%s : pas de TVA appelée - le livre de la société n\'est pas assujetti, ne connaît pas ce code ou n\'y collecte pas de TVA.', $lease->getTaxCode());
        }

        return sprintf('%s (%s %%) sur le loyer%s', $tax->getCode(), number_format($tax->getRate() / 100, 2, ',', ' '), $lease->isTaxCharges() ? ' et les charges' : '');
    }

    private function signatureState(Lease $lease): string
    {
        $parts = [];
        foreach ([LeaseSignatures::LEASE => 'Bail', LeaseSignatures::INVENTORY => 'État des lieux'] as $kind => $label) {
            $state = $this->signatures?->state($lease, $kind);
            if (null !== $state) {
                $parts[] = $label.' : '.$state['label'];
            }
        }

        return $parts ? implode(' · ', $parts) : 'Pas encore envoyé à la signature';
    }

    /** Its tenants' folders: the cloud never stops a lease being saved, it says so instead. */
    private function syncFolders(Lease $lease): void
    {
        try {
            $this->folders?->sync($lease);
        } catch (\Throwable $e) {
            $this->addFlash('warning', sprintf('Le dossier cloud des locataires n\'a pas pu être créé : %s', $e->getMessage()));
        }
    }
}
