<?php

namespace Base\Estate\Form;

use Base\Estate\Model\SuretyTerms;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A guarantor's commitment before their deed is sent: the limit, the duration, joint or not, their address. */
final class SuretyTermsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('limit', MoneyType::class, ['label' => '@estate.surety.limit', 'help' => '@estate.surety.limit_help', 'currency' => 'EUR', 'divisor' => 100, 'required' => true])
            ->add('until', DateType::class, ['label' => '@estate.surety.until', 'help' => '@estate.surety.until_help', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('joint', CheckboxType::class, ['label' => '@estate.surety.joint', 'help' => '@estate.surety.joint_help', 'required' => false])
            ->add('address', TextType::class, ['label' => '@estate.surety.address', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SuretyTerms::class, 'translation_domain' => 'estate']);
    }
}
