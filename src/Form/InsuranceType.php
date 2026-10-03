<?php

namespace Base\Estate\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** A tenant sends this year's certificate of home insurance: the file, and until when it covers them. */
final class InsuranceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, ['label' => '@estate.insurance.file', 'constraints' => [new Assert\NotNull(), new Assert\File(maxSize: '20M', mimeTypes: ['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/webp'], mimeTypesMessage: '@estate.document.type')]])
            ->add('until', DateType::class, ['label' => '@estate.insurance.until', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotNull(), new Assert\GreaterThan('today')]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'estate']);
    }
}
