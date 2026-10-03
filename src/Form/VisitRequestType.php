<?php

namespace Base\Estate\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** "Demander une visite", on a unit's page. The last field is a trap for robots. */
final class VisitRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => '@estate.visit.name', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)], 'attr' => ['autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => '@estate.visit.email', 'constraints' => [new Assert\NotBlank(), new Assert\Email()], 'attr' => ['autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => '@estate.visit.phone', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('message', TextareaType::class, ['label' => '@estate.visit.message', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 10, max: 3000)], 'attr' => ['rows' => 4]])
            ->add('website', TextType::class, ['required' => false, 'label' => false, 'row_attr' => ['class' => 'estate-trap', 'aria-hidden' => 'true'], 'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']]);
    }

    public function getBlockPrefix(): string
    {
        return 'visit';
    }

    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'estate']);
    }
}
