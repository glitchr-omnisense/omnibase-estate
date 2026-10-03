<?php

namespace Base\Estate\Form;

use Base\Estate\Model\ApplicationRequest;
use Base\Estate\Enum\Guarantee;
use Base\Estate\Enum\Occupation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Applying for a free home: who, how many, from when, situation and income, guarantee,
 * the DossierFacile link, a word - and the consent to the file being kept (three months
 * after the decision). `website` is a trap for robots.
 */
final class ApplicationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => '@estate.application.first_name', 'attr' => ['autocomplete' => 'given-name']])
            ->add('lastName', TextType::class, ['label' => '@estate.application.last_name', 'attr' => ['autocomplete' => 'family-name']])
            ->add('email', EmailType::class, ['label' => '@estate.application.email', 'attr' => ['autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => '@estate.application.phone', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('occupants', IntegerType::class, ['label' => '@estate.application.occupants', 'attr' => ['min' => 1, 'max' => 12]])
            ->add('moveInOn', DateType::class, ['label' => '@estate.application.move_in', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('occupation', EnumType::class, ['label' => '@estate.application.occupation', 'class' => Occupation::class, 'choice_label' => fn (Occupation $o) => '@estate.application.occupations.'.$o->value])
            ->add('income', MoneyType::class, ['label' => '@estate.application.income', 'help' => '@estate.application.income_help', 'currency' => false, 'divisor' => 100, 'scale' => 0, 'attr' => ['inputmode' => 'numeric']])
            ->add('guarantee', EnumType::class, ['label' => '@estate.application.guarantee', 'class' => Guarantee::class, 'expanded' => true, 'choice_label' => fn (Guarantee $g) => '@estate.application.guarantees.'.$g->value])
            ->add('dossierUrl', UrlType::class, ['label' => '@estate.application.dossier', 'help' => '@estate.application.dossier_help', 'help_html' => true, 'required' => false, 'default_protocol' => 'https', 'attr' => ['placeholder' => 'https://www.dossierfacile.logement.gouv.fr/file/…']])
            ->add('message', TextareaType::class, ['label' => '@estate.application.message', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('consent', CheckboxType::class, ['label' => '@estate.application.consent', 'mapped' => false, 'constraints' => [new Assert\IsTrue(message: '@estate.application.consent_needed')]])
            ->add('website', TextType::class, ['mapped' => false, 'required' => false, 'label' => false, 'row_attr' => ['class' => 'estate-trap', 'aria-hidden' => 'true'], 'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']]);
    }

    public function getBlockPrefix(): string
    {
        return 'application';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApplicationRequest::class, 'translation_domain' => 'estate']);
    }
}
