<?php

declare(strict_types=1);

namespace App\Webhooks\UI\Form;

use App\Webhooks\Domain\Enum\WebhookEvent;

use function sprintf;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulario de alta y edición de un suscriptor de webhooks (Fase 20).
 *
 * ## La URL se valida con `Url`, no con un patrón
 *
 * Un patrón casero de URL rechaza direcciones válidas y acepta inválidas. El
 * validador de Symfony conoce los esquemas, el puerto y el host, y con
 * `requireTld` deja fuera `http://localhost` — que es justo lo que hay que
 * dejar fuera en producción. Para probar en local se expone el receptor por
 * un túnel https, que es lo que se va a usar de verdad.
 *
 * ## Los eventos son obligatorios
 *
 * Un suscriptor sin eventos no recibe nada y no se puede distinguir de uno
 * roto. El campo lo exige el formulario, y el mensaje se cuelga del propio
 * campo para que se lea al lado de las casillas.
 *
 * @extends AbstractType<WebhookFormData>
 */
final class WebhookFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nombre',
            'required' => true,
            'attr' => [
                'maxlength' => 100,
                'placeholder' => 'ERP de contabilidad',
                'autocapitalize' => 'none',
            ],
            'help' => 'Para qué es este receptor. Solo lo ves tú.',
            'constraints' => [
                new Assert\NotBlank(message: 'El suscriptor necesita un nombre.'),
                new Assert\Length(max: 100, maxMessage: 'El nombre no puede pasar de 100 caracteres.'),
            ],
        ]);

        $builder->add('url', TextType::class, [
            'label' => 'URL de destino',
            'required' => true,
            'attr' => [
                'placeholder' => 'https://tu-sistema.example.com/webhooks/suscripta',
                'autocomplete' => 'off',
                'spellcheck' => 'false',
            ],
            'help' => 'Tiene que ser https:// y responder en menos de 10 segundos con un 2xx.',
            'constraints' => [
                new Assert\NotBlank(message: 'El suscriptor necesita una URL.'),
                new Assert\Length(max: 2048),
                new Assert\Url(
                    requireTld: true,
                    protocols: ['https'],
                    message: 'La URL tiene que ser una dirección https:// completa.',
                ),
            ],
        ]);

        $builder->add('events', ChoiceType::class, [
            'label' => 'Eventos',
            'required' => true,
            'multiple' => true,
            'expanded' => true,
            'choices' => WebhookEvent::cases(),
            'choice_value' => static fn (?WebhookEvent $event): ?string => $event?->value,
            'choice_label' => static fn (WebhookEvent $event): string => sprintf(
                '%s — %s',
                $event->value,
                $event->label(),
            ),
            'help' => 'Solo se avisa de los eventos marcados. Los que no están marcados no se encolan.',
        ]);

        $builder->add('active', CheckboxType::class, [
            'label' => 'Activo',
            'required' => false,
            'help' => 'Un suscriptor pausado no recibe nada, pero conserva su historial.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WebhookFormData::class,
        ]);
    }
}
