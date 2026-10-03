<?php

namespace Base\Estate\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** The account an invitation creates: a username, a password typed twice. */
final class InvitationAcceptType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, ['label' => '@estate.invitation.username', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 3, max: 60), new Assert\Regex('/^[\p{L}\p{N}._-]+$/u', message: 'invitation.username_chars')], 'attr' => ['autocomplete' => 'username']])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => '@estate.invitation.password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => '@estate.invitation.password_again', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => '@estate.invitation.password_mismatch',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 10, max: 4096)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'estate']);
    }
}
