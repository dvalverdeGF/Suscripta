<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{displayName: string, email: string, plainPassword: string, acceptTerms: bool}>
 */
final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['autocomplete' => 'name', 'autofocus' => true],
                'constraints' => [
                    new Assert\NotBlank(message: 'Indica tu nombre.'),
                    new Assert\Length(max: 120),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo electrónico',
                'attr' => ['autocomplete' => 'email', 'inputmode' => 'email'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Indica tu correo electrónico.'),
                    new Assert\Email(message: 'El correo no tiene un formato válido.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Contraseña',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Repite la contraseña',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'Las contraseñas no coinciden.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Elige una contraseña.'),
                    new Assert\Length(
                        min: 10,
                        max: 4096,
                        minMessage: 'La contraseña debe tener al menos {{ limit }} caracteres.',
                    ),
                ],
            ])
            ->add('acceptTerms', CheckboxType::class, [
                'label' => 'Acepto la política de privacidad y el tratamiento de mis datos.',
                'mapped' => false,
                'constraints' => [
                    new Assert\IsTrue(message: 'Debes aceptar la política de privacidad.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}
