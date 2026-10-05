<?php

declare(strict_types=1);

namespace App\Mailbox\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulario de la lista de remitentes autorizados a reenviar.
 *
 * No se pide la dirección de ingesta: la genera el sistema y no se elige. Lo
 * único que decide el usuario es **quién** puede escribir en ella, que es la
 * defensa real contra facturas inyectadas (SECURITY.md §2.4).
 *
 * @extends AbstractType<ForwardingFormData>
 */
final class ForwardingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('senders', TextareaType::class, [
            'label' => 'Remitentes autorizados',
            'required' => false,
            'attr' => [
                'rows' => 4,
                'placeholder' => "yo@miempresa.com\n@migestoria.com",
                'autocapitalize' => 'none',
                'spellcheck' => 'false',
            ],
            'help' => 'Una dirección o un dominio por línea. Un dominio se escribe con arroba delante (@migestoria.com) y autoriza a cualquier dirección de ese dominio. Si lo dejas vacío, nadie podrá reenviar.',
            'constraints' => [new Assert\Length(max: 2000)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ForwardingFormData::class,
        ]);
    }
}
