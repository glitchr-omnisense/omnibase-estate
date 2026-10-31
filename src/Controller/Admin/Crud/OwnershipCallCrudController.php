<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Service\Ownerships;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The calls for funds: each shared invoice's parts, and the provisions
 * called by hand (« Créer » : a co-owner, what for, how much, by when).
 * « Envoyer » e-mails one (again, as a reminder); « Encaisser » records a
 * payment the bank reconciliation did not match.
 */
class OwnershipCallCrudController extends AbstractEstateCrudController
{
    public const STATUSES = ['called' => 'Appelé', 'partial' => 'Partiel', 'paid' => 'Payé', 'late' => 'En retard'];

    private ?Ownerships $ownerships = null;

    #[Required]
    public function setOwnerships(Ownerships $ownerships): void
    {
        $this->ownerships = $ownerships;
    }

    public static function getEntityFqcn(): string { return OwnershipCall::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-hand-holding-dollar'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['issuedOn' => 'DESC', 'id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $send = Action::new('estateOwnershipSend', 'Envoyer', 'fa-solid fa-paper-plane')
            ->linkToRoute('estate_admin_ownership_call_send', fn (OwnershipCall $c) => ['id' => $c->getId(), '_token' => $this->actionToken('estate_ownership_call_send', $c->getId())])
            ->displayIf(fn (OwnershipCall $c) => !$c->isPaid() && null !== $c->getShare()->getEmail());
        $collect = Action::new('estateOwnershipCollect', 'Encaisser', 'fa-solid fa-hand-holding-dollar')
            ->linkToRoute('estate_admin_ownership_call_payment', fn (OwnershipCall $c) => ['id' => $c->getId()])
            ->displayIf(fn (OwnershipCall $c) => !$c->isPaid());

        return parent::configureActions($actions)->disable(Action::EDIT)
            ->add(Action::INDEX, $send)->add(Action::DETAIL, $send)->add(Action::INDEX, $collect)->add(Action::DETAIL, $collect);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('reference', 'Référence')->hideOnForm();
        yield AssociationField::new('share', 'Indivisaire')->setFormType(EntityType::class)->setFormTypeOptions(['class' => OwnershipShare::class])->setColumns(4);
        yield TextField::new('label', 'Objet')->setColumns(4);
        yield $this->euros('amount', 'Montant')->setColumns(2);
        yield DateField::new('dueOn', 'À régler avant le')->setColumns(2);
        yield $this->euros('paid', 'Reçu')->hideOnForm();
        yield TextField::new('status', 'État')->hideOnForm()->formatValue(fn ($status) => self::STATUSES[$status] ?? $status);
        yield AssociationField::new('invoice', 'Facture')->hideOnForm()->hideOnIndex();
        yield DateField::new('issuedOn', 'Émis le')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('sentAt', 'Envoyé le')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): OwnershipCall
    {
        return new OwnershipCall();
    }

    /** A provision called by hand: numbered as the invoices' parts are. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        $this->ownerships?->number($entity);
        parent::persistEntity($entityManager, $entity);
    }
}
