<?php

namespace Base\Estate\Form;

use Base\Estate\Enum\DocumentKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** A contractor adds a quote, an invoice or a photo to a work order. */
final class WorkFileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', EnumType::class, ['label' => '@estate.document.kind', 'class' => DocumentKind::class, 'choices' => DocumentKind::work(), 'choice_label' => fn (DocumentKind $k) => '@estate.document.kinds.'.$k->value, 'expanded' => true, 'data' => DocumentKind::PHOTO])
            ->add('file', FileType::class, ['label' => '@estate.document.file', 'constraints' => [new Assert\NotNull(), new Assert\File(maxSize: '20M', mimeTypes: ['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/webp'], mimeTypesMessage: '@estate.document.type')]])
            ->add('title', TextType::class, ['label' => '@estate.document.title', 'required' => false, 'constraints' => [new Assert\Length(max: 160)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'estate']);
    }
}
