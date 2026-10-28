<?php

declare(strict_types=1);

namespace App\Website\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * El formulario de contacto.
 *
 * Usa el componente Form y no un `<form>` a mano porque así la validación de
 * `ContactMessageData`, el token CSRF y la repoblación tras un error vienen
 * dados: escribir eso a mano es la forma habitual de olvidar el CSRF.
 *
 * @extends AbstractType<ContactMessageData>
 */
final class ContactFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Tu nombre',
                'attr' => ['autocomplete' => 'name', 'placeholder' => 'Ana Martín'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Tu correo',
                'attr' => ['autocomplete' => 'email', 'placeholder' => 'ana@miempresa.com'],
            ])
            ->add('company', TextType::class, [
                'label' => 'Empresa (opcional)',
                'required' => false,
                'attr' => ['autocomplete' => 'organization'],
            ])
            ->add('topic', ChoiceType::class, [
                'label' => '¿De qué va?',
                'choices' => [
                    'El producto' => 'producto',
                    'Precios y planes' => 'precios',
                    'Integraciones y API' => 'integraciones',
                    'Seguridad y datos' => 'seguridad',
                    'Otra cosa' => 'otro',
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Tu mensaje',
                'attr' => ['rows' => 6, 'placeholder' => 'Tenemos el correo de facturación en tres buzones distintos y nadie sabe qué se paga…'],
            ])
            ->add('consent', CheckboxType::class, [
                'label' => 'Acepto que me contestéis a este correo.',
                'required' => false,
                'help' => 'Solo para responderte. No te apuntamos a ninguna lista.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactMessageData::class,
        ]);
    }
}
