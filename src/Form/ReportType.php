<?php

namespace Base\Estate\Form;

use Base\Estate\Enum\Urgency;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** A tenant reports a problem: what, where, how urgent. */
final class ReportType extends AbstractType
{
    public const CATEGORIES = ['Plomberie', 'Chauffage', 'Électricité', 'Serrurerie', 'Menuiserie', 'Humidité', 'Parties communes', 'Autre'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => '@estate.report.title', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 160)]])
            ->add('category', ChoiceType::class, ['label' => '@estate.report.category', 'choices' => array_combine(self::CATEGORIES, self::CATEGORIES)])
            ->add('urgency', EnumType::class, ['label' => '@estate.report.urgency', 'class' => Urgency::class, 'choice_label' => fn (Urgency $u) => '@estate.urgency.'.$u->value, 'expanded' => true, 'data' => Urgency::NORMAL])
            ->add('description', TextareaType::class, ['label' => '@estate.report.description', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 10, max: 5000)], 'attr' => ['rows' => 5]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'estate']);
    }
}
