<?php

namespace Base\Estate\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** What a contractor says of a job: when they come, their quote, what they did, their invoice. */
final class ContractorUpdateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('visitAt', DateTimeType::class, ['label' => '@estate.contractor.visit_at', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('quote', MoneyType::class, ['label' => '@estate.contractor.quote', 'required' => false, 'currency' => false, 'divisor' => 100, 'input' => 'integer'])
            ->add('report', TextareaType::class, ['label' => '@estate.contractor.report', 'required' => false, 'attr' => ['rows' => 4]])
            ->add('invoice', MoneyType::class, ['label' => '@estate.contractor.invoice', 'required' => false, 'currency' => false, 'divisor' => 100, 'input' => 'integer'])
            ->add('invoiceNumber', TextType::class, ['label' => '@estate.contractor.invoice_number', 'required' => false])
            ->add('done', CheckboxType::class, ['label' => '@estate.contractor.done', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'estate']);
    }
}
