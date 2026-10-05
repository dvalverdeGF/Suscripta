<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use App\Identity\Domain\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Datos de perfil editables.
 *
 * El correo se puede cambiar, pero al hacerlo la cuenta vuelve a estado "sin
 * verificar": la dirección nueva no está demostrada todavía.
 *
 * @extends AbstractType<array{displayName: string, email: string, locale: string, timezone: string}>
 */
final class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['autocomplete' => 'name'],
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
            ->add('locale', TextType::class, [
                'label' => 'Idioma',
                'attr' => ['autocomplete' => 'language'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Indica tu idioma.'),
                    new Assert\Length(max: 10),
                ],
            ])
            ->add('timezone', TextType::class, [
                'label' => 'Zona horaria',
                'help' => 'Determina cuándo se consideran "hoy" los vencimientos y renovaciones.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Indica tu zona horaria.'),
                    new Assert\Length(max: 64),
                    new Assert\Timezone(message: 'La zona horaria no es válida.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'empty_data' => static fn (): array => [
                'displayName' => '',
                'email' => '',
                'locale' => 'es',
                'timezone' => 'Europe/Madrid',
            ],
        ]);
    }

    /**
     * @return array{displayName: string, email: string, locale: string, timezone: string}
     */
    public static function fromUser(User $user): array
    {
        return [
            'displayName' => $user->getDisplayName(),
            'email' => $user->getEmail(),
            'locale' => $user->getLocale(),
            'timezone' => $user->getTimezone(),
        ];
    }
}
