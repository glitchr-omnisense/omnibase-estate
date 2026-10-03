<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\MoneyField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Base\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/** The estate screens' shared pieces: an enum as a select, euros stored as cents, the actions' tokens. */
abstract class AbstractEstateCrudController extends AbstractCrudController
{
    private ?EntityManagerInterface $estateEntityManager = null;

    #[Required]
    public function setEstateEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->estateEntityManager = $entityManager;
    }

    /** The screens' names, singular and plural. */
    private const LABELS = [
        'Organisation' => ['Société', 'Sociétés'], 'Member' => ['Membre de la gestion', 'Équipe de gestion'],
        'Associate' => ['Associé·e', 'Associés'], 'Building' => ['Immeuble', 'Immeubles'], 'Unit' => ['Bien', 'Biens'],
        'UnitPhoto' => ['Photo', 'Photos des biens'], 'Lease' => ['Bail', 'Baux'], 'LeaseParty' => ['Locataire ou garant', 'Locataires et garants'],
        'RentCall' => ['Appel de loyer', 'Appels de loyer'], 'Payment' => ['Paiement', 'Paiements reçus'], 'Contractor' => ['Artisan', 'Artisans'],
        'WorkOrder' => ['Intervention', 'Interventions'], 'Notice' => ['Avis aux locataires', 'Avis aux locataires'], 'Deadline' => ['Échéance', 'Échéances'],
        'Invitation' => ['Invitation', 'Invitations'], 'Activity' => ['Activité', 'Journal d\'activité'],
        'Document' => ['Document', 'Documents des biens'], 'Application' => ['Candidature', 'Candidatures'],
    ];

    public function configureCrud(Crud $crud): Crud
    {
        [$singular, $plural] = self::LABELS[(new \ReflectionClass(static::getEntityFqcn()))->getShortName()] ?? [null, null];

        return parent::configureCrud($crud)->setEntityLabelInSingular($singular)->setEntityLabelInPlural($plural);
    }

    /** The management (ROLE_ADMIN) runs these screens - not only the super-admin, the admin's default. */
    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->setPermissions(array_fill_keys(
            [Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE, Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_ADD_ANOTHER],
            'ROLE_ADMIN',
        ));
    }

    /** @param class-string<\BackedEnum> $enum */
    protected function enum(string $property, string $label, string $enum, ?callable $labels = null): TextField
    {
        return TextField::new($property, $label)
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => $enum] + ($labels ? ['choice_label' => $labels] : []))
            ->formatValue(fn ($value) => $value instanceof \BackedEnum ? ($labels ? $labels($value) : $value->value) : $value);
    }

    protected function euros(string $property, string $label): MoneyField
    {
        return MoneyField::new($property, $label)->setCurrency('EUR')->setStoredAsCents();
    }

    /**
     * A new record of a screen: its required company, unit or lease
     * pre-filled with the first there is (most install one company), so the
     * form opens; it stays an ordinary field to change.
     */
    public function createEntity(string $entityFqcn): object
    {
        $reflection = new \ReflectionClass($entityFqcn);
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isOptional()) {
                break;
            }
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
            $arguments[] = match (true) {
                'string' === $name => '',
                'int' === $name => 0,
                \DateTimeImmutable::class === $name => new \DateTimeImmutable('today'),
                enum_exists($name) => $name::cases()[0],
                class_exists($name) => $this->estateEntityManager->getRepository($name)->findOneBy([], ['id' => 'ASC'])
                    ?? throw new \RuntimeException(sprintf('Create a %s first.', (new \ReflectionClass($name))->getShortName())),
                default => null,
            };
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /** A token in the action's link: a link from elsewhere, a prefetch, does nothing (ActionController checks it). */
    protected function actionToken(string $action, ?int $id): string
    {
        return $this->container->get('security.csrf.token_manager')->getToken($action.'_'.$id)->getValue();
    }
}
